<?php

declare(strict_types=1);

use App\Enums\VpsHealthStatus;
use App\Services\Vps\VpsHealthService;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as SymfonyProcessException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * The exception a probe that has to be killed on its timeout raises.
 */
function vpsProbeTimeout(string $command): ProcessTimedOutException
{
    $process = SymfonyProcess::fromShellCommandline($command)->setTimeout(2);

    return new ProcessTimedOutException(
        new SymfonyTimedOutException($process, SymfonyTimedOutException::TYPE_GENERAL),
        new ProcessResult($process),
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function vpsSnapshot(array $overrides = []): array
{
    return array_replace_recursive([
        'generated_at' => now()->getTimestamp(),
        'host' => 'health-host',
        'yesterday' => ['covers' => '2026-08-12', 'cpu_avg_pct' => 36.4, 'load_peak' => 6.62],
        'trends' => [
            'window_days' => 30,
            'min_span_days' => 7,
            'disk' => ['slope_kb_per_day' => null, 'days_to_target' => null, 'state' => 'unknown', 'samples' => 6, 'span_days' => 0.1],
            'memory' => ['slope_pct_per_day' => null, 'days_to_target' => null, 'state' => 'unknown'],
            'database' => ['slope_bytes_per_day' => null],
        ],
        'sizes' => ['database_bytes' => 569851904, 'docker_bytes' => 928500000],
        'cert' => ['days_remaining' => 84, 'state' => 'ok'],
        'failed_units' => [],
        'reboot_required' => false,
        'reboot_detail' => 'not required',
        'thresholds' => ['disk_warn_pct' => 80, 'disk_crit_pct' => 90, 'mem_target_pct' => 90, 'cert_warn_days' => 21],
    ], $overrides);
}

beforeEach(function (): void {
    $this->vpsRoot = storage_path('framework/testing/vps-'.Str::random(8));

    File::ensureDirectoryExists($this->vpsRoot.'/proc');
    File::ensureDirectoryExists($this->vpsRoot.'/requests');

    File::put($this->vpsRoot.'/proc/meminfo', implode("\n", [
        'MemTotal:       32000000 kB',
        'MemFree:         1000000 kB',
        'MemAvailable:    8000000 kB',
        'SwapTotal:       2000000 kB',
        'SwapFree:        1500000 kB',
    ]));
    File::put($this->vpsRoot.'/proc/loadavg', "1.50 1.20 0.90 2/500 12345\n");
    File::put($this->vpsRoot.'/proc/uptime', "123456.78 987654.32\n");
    File::put($this->vpsRoot.'/proc/stat', implode("\n", [
        'cpu  100 0 50 850 0 0 0 0 0 0',
        'cpu0 50 0 25 425 0 0 0 0 0 0',
        'cpu1 50 0 25 425 0 0 0 0 0 0',
    ]));
    File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot()));

    config()->set('vps.proc_path', $this->vpsRoot.'/proc');
    config()->set('vps.snapshot_path', $this->vpsRoot.'/status.json');
    config()->set('vps.refresh_request_path', $this->vpsRoot.'/requests/refresh');
    config()->set('vps.disk_mount', $this->vpsRoot);
    config()->set('vps.services', ['nginx']);
});

afterEach(function (): void {
    File::deleteDirectory($this->vpsRoot);
});

describe('live readings', function (): void {
    it('derives memory and swap usage from procfs', function (): void {
        $memory = resolve(VpsHealthService::class)->memory();

        expect($memory['total_kb'])->toBe(32000000)
            ->and($memory['available_kb'])->toBe(8000000)
            ->and($memory['used_kb'])->toBe(24000000)
            ->and($memory['used_pct'])->toBe(75.0)
            ->and($memory['swap_used_kb'])->toBe(500000)
            ->and($memory['swap_used_pct'])->toBe(25.0);
    });

    it('reads load averages against the core count', function (): void {
        $load = resolve(VpsHealthService::class)->load();

        expect($load['load1'])->toBe(1.5)
            ->and($load['load15'])->toBe(0.9)
            ->and($load['cpu_count'])->toBe(2)
            ->and($load['load_per_cpu'])->toBe(0.75);
    });

    it('reports every reading as unknown rather than zero when procfs is unreachable', function (): void {
        config()->set('vps.proc_path', $this->vpsRoot.'/nothing-here');

        $service = resolve(VpsHealthService::class);

        expect($service->memory()['used_pct'])->toBeNull()
            ->and($service->memory()['total_kb'])->toBeNull()
            ->and($service->load()['load1'])->toBeNull()
            ->and($service->uptimeSeconds())->toBeNull()
            ->and($service->cpuUsagePct())->toBeNull();
    });

    it('reads uptime and disk usage', function (): void {
        $service = resolve(VpsHealthService::class);
        $disk = $service->disk();

        expect($service->uptimeSeconds())->toBe(123456)
            ->and($disk['total_bytes'])->toBeGreaterThan(0)
            ->and($disk['free_bytes'])->toBeGreaterThan(0)
            ->and($disk['used_bytes'])->toBe($disk['total_bytes'] - $disk['free_bytes'])
            ->and($disk['used_pct'])->toEqualWithDelta($disk['used_bytes'] / $disk['total_bytes'] * 100, 0.0001);
    });

    it('reports the failed job count as unknown when the table cannot be read', function (): void {
        config()->set('queue.failed.table', '');

        expect(resolve(VpsHealthService::class)->failedJobs())->toBeNull();
    });
});

describe('cpu sampling', function (): void {
    it('reports nothing on the first read and a delta on the next', function (): void {
        expect(resolve(VpsHealthService::class)->cpuUsagePct())->toBeNull();

        $this->travel(10)->seconds();

        File::put($this->vpsRoot.'/proc/stat', implode("\n", [
            'cpu  200 0 100 1700 0 0 0 0 0 0',
            'cpu0 100 0 50 850 0 0 0 0 0 0',
            'cpu1 100 0 50 850 0 0 0 0 0 0',
        ]));

        expect(resolve(VpsHealthService::class)->cpuUsagePct())->toEqualWithDelta(15.0, 0.001);
    });

    it('discards a sample older than the configured window', function (): void {
        resolve(VpsHealthService::class)->cpuUsagePct();

        $this->travel(config()->integer('vps.cpu_sample_max_age_seconds') + 60)->seconds();

        expect(resolve(VpsHealthService::class)->cpuUsagePct())->toBeNull();
    });
});

describe('service probes', function (): void {
    it('reads the state systemctl prints regardless of its exit code', function (): void {
        config()->set('vps.services', ['nginx', 'mysql']);

        Process::fake([
            '*is-active*nginx*' => Process::result('active'),
            '*is-active*mysql*' => Process::result(output: 'failed', exitCode: 3),
        ]);

        expect(resolve(VpsHealthService::class)->services())->toBe([
            ['unit' => 'nginx', 'state' => 'active', 'status' => VpsHealthStatus::Ok],
            ['unit' => 'mysql', 'state' => 'failed', 'status' => VpsHealthStatus::Critical],
        ]);
    });

    it('reports a unit as unknown rather than healthy when the probe cannot run', function (): void {
        Process::fake([
            '*is-active*' => fn (): never => throw new SymfonyProcessException('Unable to launch a new process.'),
        ]);

        expect(resolve(VpsHealthService::class)->services())->toBe([
            ['unit' => 'nginx', 'state' => null, 'status' => VpsHealthStatus::Unknown],
        ]);
    });

    it('reads a unit that is mid-restart as transient rather than as an outage', function (): void {
        Process::fake(['*is-active*nginx*' => Process::result(output: 'activating', exitCode: 3)]);

        expect(resolve(VpsHealthService::class)->services())->toBe([
            ['unit' => 'nginx', 'state' => 'activating', 'status' => VpsHealthStatus::Warning],
        ]);
    });

    it('keeps the states it collected when one probe times out', function (): void {
        config()->set('vps.services', ['nginx', 'php-fpm', 'redis-server']);

        Process::fake([
            '*is-active*php-fpm*' => fn (): never => throw vpsProbeTimeout('systemctl is-active php-fpm'),
            '*is-active*nginx*' => Process::result('active'),
            '*is-active*redis-server*' => Process::result('active'),
        ]);

        expect(resolve(VpsHealthService::class)->services())->toBe([
            ['unit' => 'nginx', 'state' => 'active', 'status' => VpsHealthStatus::Ok],
            ['unit' => 'php-fpm', 'state' => null, 'status' => VpsHealthStatus::Unknown],
            ['unit' => 'redis-server', 'state' => 'active', 'status' => VpsHealthStatus::Ok],
        ]);
    });

    it('probes the units once per interval rather than once per read', function (): void {
        Process::fake(['*is-active*nginx*' => Process::result('active')]);

        resolve(VpsHealthService::class)->services();
        resolve(VpsHealthService::class)->services();

        Process::assertRanTimes(
            fn (PendingProcess $process): bool => is_array($process->command) && in_array('is-active', $process->command, true),
            1,
        );
    });
});

describe('snapshot', function (): void {
    it('reads a fresh snapshot and exposes its published figures', function (): void {
        $service = resolve(VpsHealthService::class);
        $snapshot = $service->snapshot();

        expect($snapshot['available'])->toBeTrue()
            ->and($snapshot['usable'])->toBeTrue()
            ->and($snapshot['stale'])->toBeFalse()
            ->and($service->host())->toBe('health-host')
            ->and($service->cert()['days_remaining'])->toBe(84)
            ->and($service->sizes()['docker_bytes'])->toBe(928500000)
            ->and($service->trends()['disk']['slope_kb_per_day'])->toBeNull()
            ->and($service->reboot()['required'])->toBeFalse();
    });

    it('marks a snapshot past the staleness limit as unusable', function (): void {
        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'generated_at' => now()->getTimestamp() - 7200,
        ])));

        $snapshot = resolve(VpsHealthService::class)->snapshot();

        expect($snapshot['available'])->toBeTrue()
            ->and($snapshot['stale'])->toBeTrue()
            ->and($snapshot['usable'])->toBeFalse()
            ->and($snapshot['age_seconds'])->toBe(7200);
    });

    it('reports a missing snapshot without inventing values', function (): void {
        config()->set('vps.snapshot_path', $this->vpsRoot.'/absent.json');

        $service = resolve(VpsHealthService::class);

        expect($service->snapshot()['available'])->toBeFalse()
            ->and($service->snapshot()['error'])->not->toBeNull()
            ->and($service->cert()['days_remaining'])->toBeNull()
            ->and($service->host())->toBeNull()
            ->and($service->failedUnits())->toBeNull();
    });

    it('never names the snapshot file in an error a browser will render', function (): void {
        $cases = [
            'absent' => fn () => config()->set('vps.snapshot_path', $this->vpsRoot.'/absent.json'),
            'empty' => fn () => File::put($this->vpsRoot.'/status.json', ''),
            'malformed' => fn () => File::put($this->vpsRoot.'/status.json', 'not json at all'),
        ];

        foreach ($cases as $case => $arrange) {
            $arrange();

            $error = resolve(VpsHealthService::class)->snapshot()['error'];

            expect($error)->toBeString()
                ->and($error)->not->toContain($this->vpsRoot, "the {$case} error leaks the snapshot path")
                ->and($error)->not->toContain('.json', "the {$case} error leaks the snapshot filename")
                ->and($error)->not->toContain('/', "the {$case} error leaks a path fragment");
        }
    });

    it('reports malformed json as unavailable', function (): void {
        File::put($this->vpsRoot.'/status.json', 'not json at all');

        expect(resolve(VpsHealthService::class)->snapshot()['available'])->toBeFalse();
    });

    it('prefers the thresholds the snapshot publishes over the local configuration', function (): void {
        config()->set('vps.thresholds.disk_crit_pct', 95.0);

        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'thresholds' => ['disk_crit_pct' => 88],
        ])));

        expect(resolve(VpsHealthService::class)->thresholds()['disk_crit_pct'])->toBe(88.0);
    });

    it('falls back to the local configuration for a threshold the snapshot omits', function (): void {
        config()->set('vps.thresholds.mem_crit_pct', 97.0);

        expect(resolve(VpsHealthService::class)->thresholds()['mem_crit_pct'])->toBe(97.0);
    });

    it('refuses the thresholds a stale snapshot publishes', function (): void {
        config()->set('vps.thresholds.disk_crit_pct', 90.0);

        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'generated_at' => now()->getTimestamp() - 7200,
            'thresholds' => ['disk_crit_pct' => 101],
        ])));

        $service = resolve(VpsHealthService::class);

        expect($service->snapshot()['usable'])->toBeFalse()
            ->and($service->thresholds()['disk_crit_pct'])->toBe(90.0);
    });

    it('takes the minimum trend span from a usable snapshot and from the configuration otherwise', function (): void {
        config()->set('vps.trends.min_span_days', 5);

        expect(resolve(VpsHealthService::class)->trends()['min_span_days'])->toBe(7);

        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'generated_at' => now()->getTimestamp() - 7200,
        ])));

        $service = resolve(VpsHealthService::class);
        $service->forgetSnapshot();

        expect($service->trends()['min_span_days'])->toBe(5);
    });

    it('reads the published timestamp off disk without disturbing the shared copy', function (): void {
        expect(resolve(VpsHealthService::class)->cert()['days_remaining'])->toBe(84);

        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'generated_at' => now()->getTimestamp() + 300,
            'cert' => ['days_remaining' => 3],
        ])));

        expect(resolve(VpsHealthService::class)->publishedAt())->toBe(now()->getTimestamp() + 300)
            ->and(resolve(VpsHealthService::class)->cert()['days_remaining'])->toBe(84);
    });
});

describe('failed units', function (): void {
    it('reports an empty list as measured and an absent one as unknown', function (): void {
        expect(resolve(VpsHealthService::class)->failedUnits())->toBe([]);

        $snapshot = vpsSnapshot();
        unset($snapshot['failed_units']);

        File::put($this->vpsRoot.'/status.json', (string) json_encode($snapshot));

        $service = resolve(VpsHealthService::class);
        $service->forgetSnapshot();

        expect($service->failedUnits())->toBeNull();
    });

    it('reports a list the host could not collect as unknown', function (): void {
        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot(['failed_units' => null])));

        expect(resolve(VpsHealthService::class)->failedUnits())->toBeNull();
    });

    it('reports a malformed list as unknown rather than as none failed', function (): void {
        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot(['failed_units' => [['nested']]])));

        expect(resolve(VpsHealthService::class)->failedUnits())->toBeNull();
    });

    it('reports the list of a stale snapshot as unknown', function (): void {
        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'generated_at' => now()->getTimestamp() - 7200,
        ])));

        expect(resolve(VpsHealthService::class)->failedUnits())->toBeNull();
    });

    it('lists the units the host reported as failed', function (): void {
        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'failed_units' => ['forge-reverb.service'],
        ])));

        expect(resolve(VpsHealthService::class)->failedUnits())->toBe(['forge-reverb.service']);
    });
});

describe('refresh requests', function (): void {
    it('creates the request file the host watches', function (): void {
        expect(resolve(VpsHealthService::class)->requestRefresh())->toBeTrue()
            ->and(File::exists($this->vpsRoot.'/requests/refresh'))->toBeTrue();
    });

    it('reports failure when the request directory does not exist', function (): void {
        config()->set('vps.refresh_request_path', $this->vpsRoot.'/gone/refresh');

        expect(resolve(VpsHealthService::class)->requestRefresh())->toBeFalse();
    });

    it('re-reads the file once the cached copy is forgotten', function (): void {
        $service = resolve(VpsHealthService::class);

        expect($service->cert()['days_remaining'])->toBe(84);

        File::put($this->vpsRoot.'/status.json', json_encode(vpsSnapshot([
            'cert' => ['days_remaining' => 3],
        ])));

        expect($service->cert()['days_remaining'])->toBe(84);

        $service->forgetSnapshot();

        expect($service->cert()['days_remaining'])->toBe(3);
    });
});
