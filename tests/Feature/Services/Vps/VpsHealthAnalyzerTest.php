<?php

declare(strict_types=1);

use App\Enums\VpsHealthStatus;
use App\Services\Vps\VpsHealthAnalyzer;
use App\Services\Vps\VpsHealthService;
use App\Support\DataTransferObjects\VpsHealthFinding;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * A snapshot in which every reading was taken and every one of them is fine, so that a test only has to say what it is
 * changing. Anything a test leaves out of the overrides is measured, not missing.
 *
 * @param  array<string, mixed>  $overrides
 */
function publishVpsSnapshot(string $root, array $overrides = []): void
{
    File::put($root.'/status.json', (string) json_encode(array_replace_recursive([
        'generated_at' => now()->getTimestamp(),
        'host' => 'health-host',
        'yesterday' => [
            'covers' => '2026-08-12',
            'cpu_avg_pct' => 36.4,
            'cpu_peak_pct' => 58.5,
            'iowait_avg_pct' => 0.42,
            'mem_peak_pct' => 61.0,
            'mem_avail_min_kb' => 8000000.0,
            'load_avg' => 1.1,
            'load_peak' => 6.62,
            'swapout_avg' => 0.0,
        ],
        'trends' => [
            'window_days' => 30,
            'min_span_days' => 7,
            'disk' => ['slope_kb_per_day' => 120000, 'days_to_target' => 400, 'state' => 'ok', 'samples' => 240, 'span_days' => 21.0],
            'memory' => ['slope_pct_per_day' => 0.01, 'days_to_target' => 800, 'state' => 'ok'],
            'database' => ['slope_bytes_per_day' => 1048576],
        ],
        'sizes' => ['database_bytes' => 569851904, 'docker_bytes' => 928500000],
        'cert' => ['days_remaining' => 84, 'state' => 'ok'],
        'failed_units' => [],
        'reboot_required' => false,
        'reboot_detail' => 'not required',
        'thresholds' => ['disk_warn_pct' => 101, 'disk_crit_pct' => 101],
    ], $overrides)));
}

function analyzeVpsHealth(): VpsHealthAnalyzer
{
    return new VpsHealthAnalyzer(resolve(VpsHealthService::class));
}

/**
 * @param  list<VpsHealthFinding>  $findings
 * @return list<string>
 */
function vpsFindingTitles(array $findings): array
{
    return array_map(static fn (VpsHealthFinding $finding): string => $finding->title, $findings);
}

function vpsFinding(VpsHealthAnalyzer $analyzer, string $title): VpsHealthFinding
{
    return collect($analyzer->findings())
        ->firstOrFail(static fn (VpsHealthFinding $finding): bool => $finding->title === $title);
}

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/vps-'.Str::random(8));

    File::ensureDirectoryExists($this->root.'/proc');

    File::put($this->root.'/proc/meminfo', implode("\n", [
        'MemTotal:       32000000 kB',
        'MemFree:         8000000 kB',
        'MemAvailable:    8000000 kB',
        'SwapTotal:       2000000 kB',
        'SwapFree:        2000000 kB',
    ]));
    File::put($this->root.'/proc/loadavg', "1.50 1.20 0.90 2/500 12345\n");
    File::put($this->root.'/proc/uptime', "123456.78 987654.32\n");
    File::put($this->root.'/proc/stat', implode("\n", [
        'cpu  100 0 50 850 0 0 0 0 0 0',
        'cpu0 50 0 25 425 0 0 0 0 0 0',
        'cpu1 50 0 25 425 0 0 0 0 0 0',
    ]));

    publishVpsSnapshot($this->root);

    config()->set('vps.proc_path', $this->root.'/proc');
    config()->set('vps.snapshot_path', $this->root.'/status.json');
    config()->set('vps.disk_mount', $this->root);
    config()->set('vps.services', ['nginx']);

    // The dashboard runs against the disk of whatever machine the suite runs on, so the local fallbacks are pushed out
    // of reach: a test that means to exercise a disk rule publishes its own thresholds.
    config()->set('vps.thresholds.disk_warn_pct', 101.0);
    config()->set('vps.thresholds.disk_crit_pct', 101.0);

    Process::fake(['*is-active*' => Process::result('active')]);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

it('reports a clean bill of health when every reading is measured and within threshold', function (): void {
    $analyzer = analyzeVpsHealth();

    expect($analyzer->findings())->toBe([])
        ->and($analyzer->verdict())->toBe(VpsHealthStatus::Ok)
        ->and($analyzer->headline())->toBe('Everything checked is healthy. No action needed.');
});

describe('immediate actions', function (): void {
    it('raises a critical finding for a unit that is not running', function (): void {
        Process::fake(['*is-active*' => Process::result(output: 'inactive', exitCode: 3)]);

        $analyzer = analyzeVpsHealth();

        expect(vpsFindingTitles($analyzer->immediate()))->toContain('nginx is not running')
            ->and($analyzer->verdict())->toBe(VpsHealthStatus::Critical)
            ->and($analyzer->headline())->toBe('One issue needs attention now.');
    });

    it('advises rather than alarms while a unit is restarting', function (): void {
        Process::fake(['*is-active*' => Process::result(output: 'activating', exitCode: 3)]);

        $analyzer = analyzeVpsHealth();

        expect($analyzer->immediate())->toBe([])
            ->and(vpsFindingTitles($analyzer->recommended()))->toContain('nginx is changing state');
    });

    it('raises a critical finding once disk passes the critical threshold', function (): void {
        publishVpsSnapshot($this->root, [
            'thresholds' => ['disk_warn_pct' => 0, 'disk_crit_pct' => 0],
        ]);

        expect(vpsFindingTitles(analyzeVpsHealth()->immediate()))->toContain('Disk is critically full');
    });

    it('judges the live tier against the local thresholds once the snapshot goes stale', function (): void {
        config()->set('vps.thresholds.disk_crit_pct', 0.0);

        publishVpsSnapshot($this->root, [
            'generated_at' => now()->getTimestamp() - 7200,
            'thresholds' => ['disk_warn_pct' => 101, 'disk_crit_pct' => 101],
        ]);

        expect(vpsFindingTitles(analyzeVpsHealth()->immediate()))->toContain('Disk is critically full');
    });

    it('raises a critical finding for a certificate about to expire', function (): void {
        publishVpsSnapshot($this->root, [
            'cert' => ['days_remaining' => 4, 'state' => 'breached'],
        ]);

        expect(vpsFindingTitles(analyzeVpsHealth()->immediate()))->toContain('TLS certificate expires imminently');
    });

    it('raises a critical finding for failed systemd units', function (): void {
        publishVpsSnapshot($this->root, [
            'failed_units' => ['forge-reverb.service'],
        ]);

        expect(vpsFindingTitles(analyzeVpsHealth()->immediate()))->toContain('1 systemd unit has failed');
    });
});

describe('recommended actions', function (): void {
    it('advises rather than alarms when disk is only past the warning threshold', function (): void {
        publishVpsSnapshot($this->root, [
            'thresholds' => ['disk_warn_pct' => 0, 'disk_crit_pct' => 101],
        ]);

        $analyzer = analyzeVpsHealth();

        expect($analyzer->immediate())->toBe([])
            ->and(vpsFindingTitles($analyzer->recommended()))->toContain('Disk is filling up')
            ->and($analyzer->verdict())->toBe(VpsHealthStatus::Warning);
    });

    it('advises on a pending reboot and a swelling docker directory', function (): void {
        publishVpsSnapshot($this->root, [
            'reboot_required' => true,
            'reboot_detail' => 'kernel 6.8.0-51 pending',
            'sizes' => ['docker_bytes' => 40 * 1024 * 1024 * 1024],
        ]);

        $titles = vpsFindingTitles(analyzeVpsHealth()->recommended());

        expect($titles)->toContain('A reboot is pending')
            ->and($titles)->toContain('Docker is using a lot of disk');
    });

    it('advises when disk growth projects past its target inside the warning window', function (): void {
        publishVpsSnapshot($this->root, [
            'trends' => ['disk' => ['slope_kb_per_day' => 500000, 'days_to_target' => 12, 'span_days' => 21]],
        ]);

        expect(vpsFindingTitles(analyzeVpsHealth()->recommended()))->toContain('Disk is trending toward full');
    });

    it('reports a trend it could not fit as unknown instead of passing over it', function (): void {
        publishVpsSnapshot($this->root, [
            'trends' => [
                'disk' => ['slope_kb_per_day' => null, 'days_to_target' => null, 'state' => 'unknown'],
                'memory' => ['slope_pct_per_day' => null, 'days_to_target' => null, 'state' => 'unknown'],
            ],
        ]);

        $analyzer = analyzeVpsHealth();
        $titles = vpsFindingTitles($analyzer->findings());

        expect($titles)->not->toContain('Disk is trending toward full')
            ->and($titles)->not->toContain('Memory use is trending upward')
            ->and($titles)->toContain('The last health check did not collect everything')
            ->and($analyzer->verdict())->not->toBe(VpsHealthStatus::Ok);

        expect(vpsFinding($analyzer, 'The last health check did not collect everything')->detail)
            ->toContain('disk growth trend')
            ->toContain('memory growth trend');
    });

    it('reports an unreadable failed job table rather than reading it as no failures', function (): void {
        config()->set('queue.failed.table', '');

        $analyzer = analyzeVpsHealth();

        expect(vpsFindingTitles($analyzer->recommended()))->toContain('The failed queue jobs could not be counted')
            ->and($analyzer->verdict())->not->toBe(VpsHealthStatus::Ok);
    });
});

describe('trustworthiness of the inputs', function (): void {
    it('never treats an unmeasured reading as healthy', function (): void {
        config()->set('vps.proc_path', $this->root.'/gone');

        $analyzer = analyzeVpsHealth();

        expect($analyzer->verdict())->toBe(VpsHealthStatus::Warning)
            ->and(vpsFindingTitles($analyzer->recommended()))->toContain('Some live readings could not be taken')
            ->and(vpsFinding($analyzer, 'Some live readings could not be taken')->detail)
            ->toContain('memory')
            ->toContain('load average');
    });

    it('does not call the host healthy when a fresh check produced no reading', function (): void {
        publishVpsSnapshot($this->root, [
            'cert' => ['days_remaining' => null, 'state' => 'unknown'],
            'sizes' => ['database_bytes' => null, 'docker_bytes' => null],
        ]);

        $analyzer = analyzeVpsHealth();

        expect($analyzer->verdict())->not->toBe(VpsHealthStatus::Ok)
            ->and($analyzer->headline())->not->toBe('Everything checked is healthy. No action needed.')
            ->and(vpsFinding($analyzer, 'The last health check did not collect everything')->detail)
            ->toContain('certificate expiry')
            ->toContain('database size')
            ->toContain('Docker size');
    });

    it('does not read an uncollected failed unit list as no units having failed', function (): void {
        publishVpsSnapshot($this->root, ['failed_units' => null]);

        $analyzer = analyzeVpsHealth();

        expect($analyzer->verdict())->not->toBe(VpsHealthStatus::Ok)
            ->and(vpsFinding($analyzer, 'The last health check did not collect everything')->detail)
            ->toContain('failed unit list');
    });

    it('does not read an uncollected set of aggregates as a quiet day', function (): void {
        publishVpsSnapshot($this->root, [
            'yesterday' => [
                'covers' => null,
                'cpu_avg_pct' => null,
                'cpu_peak_pct' => null,
                'iowait_avg_pct' => null,
                'mem_peak_pct' => null,
                'mem_avail_min_kb' => null,
                'load_avg' => null,
                'load_peak' => null,
                'swapout_avg' => null,
            ],
        ]);

        $analyzer = analyzeVpsHealth();

        expect($analyzer->verdict())->not->toBe(VpsHealthStatus::Ok)
            ->and(vpsFinding($analyzer, 'The last health check did not collect everything')->detail)
            ->toContain("yesterday's aggregates");
    });

    it('stops drawing conclusions from a stale snapshot and reports the staleness instead', function (): void {
        publishVpsSnapshot($this->root, [
            'generated_at' => now()->getTimestamp() - 7200,
            'cert' => ['days_remaining' => 2, 'state' => 'breached'],
            'reboot_required' => true,
        ]);

        $analyzer = analyzeVpsHealth();

        expect($analyzer->immediate())->toBe([])
            ->and(vpsFindingTitles($analyzer->recommended()))->toBe(['The health snapshot is stale']);
    });

    it('reports a missing snapshot as the only snapshot-derived finding', function (): void {
        config()->set('vps.snapshot_path', $this->root.'/absent.json');

        expect(vpsFindingTitles(analyzeVpsHealth()->recommended()))->toBe(['No health snapshot is available']);
    });
});
