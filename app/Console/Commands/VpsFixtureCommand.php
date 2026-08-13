<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Vps\VpsHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Description('Generate local fixtures so the VPS Health dashboard can be exercised without a real host')]
#[Signature('vps:fixture {--scenario=healthy : healthy|warning|critical|unknown|stale|missing} {--watch : Advance the CPU counters and service refresh requests} {--minutes=60 : How long --watch runs before exiting on its own}')]
final class VpsFixtureCommand extends Command
{
    private const array SCENARIOS = ['healthy', 'warning', 'critical', 'unknown', 'stale', 'missing'];

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Refusing to run outside the local environment: this overwrites health data with fiction.');

            return self::FAILURE;
        }

        $scenario = (string) $this->option('scenario');

        if (! in_array($scenario, self::SCENARIOS, true)) {
            $this->error(sprintf('Unknown scenario "%s". Expected one of: %s', $scenario, implode(', ', self::SCENARIOS)));

            return self::FAILURE;
        }

        $root = storage_path('app/vps-fixture');

        File::ensureDirectoryExists($root.'/proc');
        File::ensureDirectoryExists($root.'/requests');
        File::ensureDirectoryExists($root.'/bin');

        $this->writeProc($root, $scenario);
        $this->writeServices($root, $scenario);
        $this->writeSystemctlStub($root);
        $this->writeSnapshot($root, $scenario);

        app(VpsHealthService::class)->forgetCaches();

        $this->info(sprintf('Fixture written for scenario "%s" at %s', $scenario, $root));
        $this->newLine();
        $this->printEnv($root);

        if ($this->option('watch')) {
            $this->newLine();
            $this->watch($root, $scenario);
        }

        return self::SUCCESS;
    }

    private function writeProc(string $root, string $scenario): void
    {
        $seconds = time();
        $busyTicks = (int) ($seconds * 100 * 3.2);
        $idleTicks = (int) ($seconds * 100 * 4.8);

        $load = match ($scenario) {
            'critical' => [22.4, 21.8, 20.1],
            'warning' => [13.9, 13.2, 12.8],
            default => [2.11, 2.04, 1.98],
        };

        $memTotalKb = 24_603_308;
        $memAvailableKb = match ($scenario) {
            'critical' => (int) ($memTotalKb * 0.03),
            'warning' => (int) ($memTotalKb * 0.12),
            default => 18_353_388,
        };

        $swapTotalKb = 4_194_300;
        $swapFreeKb = in_array($scenario, ['warning', 'critical'], true)
            ? (int) ($swapTotalKb * 0.7)
            : $swapTotalKb;

        File::put($root.'/proc/meminfo', implode("\n", [
            sprintf('MemTotal:       %d kB', $memTotalKb),
            sprintf('MemFree:        %d kB', (int) ($memAvailableKb * 0.9)),
            sprintf('MemAvailable:   %d kB', $memAvailableKb),
            sprintf('SwapTotal:      %d kB', $swapTotalKb),
            sprintf('SwapFree:       %d kB', $swapFreeKb),
        ])."\n");

        File::put($root.'/proc/loadavg', sprintf("%.2f %.2f %.2f 2/401 %d\n", $load[0], $load[1], $load[2], $seconds));

        $lines = [
            sprintf('cpu  %d 4200 %d %d 18000 0 %d 0 0 0', (int) ($busyTicks * 0.6), (int) ($busyTicks * 0.4), $idleTicks, (int) ($busyTicks * 0.02)),
        ];

        for ($core = 0; $core < 8; $core++) {
            $lines[] = sprintf(
                'cpu%d %d 500 %d %d 2200 0 900 0 0 0',
                $core,
                (int) ($busyTicks * 0.075),
                (int) ($busyTicks * 0.05),
                (int) ($idleTicks / 8),
            );
        }

        File::put($root.'/proc/stat', implode("\n", [
            ...$lines,
            'ctxt 998877665',
            sprintf('btime %d', $seconds - 125_465),
            'processes 4410221',
            'procs_running 8',
            'procs_blocked 0',
        ])."\n");

        File::put($root.'/proc/uptime', "125465.00 900000.00\n");
    }

    /**
     * The unit states the stub systemctl will report back.
     */
    private function writeServices(string $root, string $scenario): void
    {
        $states = [];

        foreach (config()->array('vps.services', []) as $unit) {
            if (is_string($unit) && $unit !== '') {
                $states[$unit] = 'active';
            }
        }

        if ($scenario === 'critical') {
            $states['forge-horizon'] = 'failed';
        }

        if ($scenario === 'warning') {
            $states['forge-reverb'] = 'activating';
        }

        if ($scenario === 'unknown') {
            // No state at all: the stub exits without printing, which is what a wedged or timed-out probe looks like.
            $states['mysql'] = '';
        }

        File::put($root.'/services.json', json_encode($states, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }

    private function writeSystemctlStub(string $root): void
    {
        File::put($root.'/bin/systemctl-stub.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            $unit = $argv[2] ?? '';
            $states = json_decode((string) file_get_contents(__DIR__.'/../services.json'), true);
            $state = is_array($states) ? ($states[$unit] ?? 'inactive') : 'inactive';

            if ($state === '') {
                exit(1);
            }

            echo $state, PHP_EOL;

            exit($state === 'active' ? 0 : 3);
            PHP);

        File::put($root.'/bin/systemctl.bat', "@echo off\r\nphp \"%~dp0systemctl-stub.php\" %*\r\n");

        File::put($root.'/bin/systemctl', "#!/bin/sh\nexec php \"$(dirname \"$0\")/systemctl-stub.php\" \"$@\"\n");

        @chmod($root.'/bin/systemctl', 0o755);
    }

    private function writeSnapshot(string $root, string $scenario): void
    {
        $path = $root.'/status.json';

        if ($scenario === 'missing') {
            File::delete($path);

            return;
        }

        $generatedAt = $scenario === 'stale' ? time() - 10_800 : time();

        $null = $scenario === 'unknown';

        $snapshot = [
            'generated_at' => $generatedAt,
            'host' => 'fixture.local',
            'yesterday' => [
                'covers' => now()->subDay()->toDateString(),
                'cpu_avg_pct' => $null ? null : 36.4,
                'cpu_peak_pct' => $null ? null : 58.5,
                'cpu_peak_at' => '15:10:01',
                'iowait_avg_pct' => $null ? null : 1.26,
                'mem_peak_pct' => $null ? null : 22.17,
                'mem_peak_at' => '00:00:03',
                'mem_avail_min_kb' => $null ? null : 18_304_620,
                'load_avg' => $null ? null : 4.49,
                'load_peak' => $null ? null : 6.62,
                'load_peak_at' => '15:10:01',
                'swapout_avg' => $null ? null : 0.0,
            ],
            'trends' => [
                'window_days' => 30,
                'min_span_days' => 7,
                'disk' => [
                    'slope_kb_per_day' => $null ? null : 1_048_576,
                    'days_to_target' => match ($scenario) {
                        'warning', 'critical' => 12,
                        'unknown' => null,
                        default => 640,
                    },
                    'state' => match ($scenario) {
                        'warning', 'critical' => 'breached',
                        'unknown' => 'unknown',
                        default => 'ok',
                    },
                    'samples' => $null ? null : 96,
                    'span_days' => $null ? null : 24.0,
                ],
                'memory' => [
                    'slope_pct_per_day' => $null ? null : 0.02,
                    'days_to_target' => $null ? null : 900,
                    'state' => $null ? 'unknown' : 'ok',
                ],
                'database' => ['slope_bytes_per_day' => $null ? null : 9_961_472],
            ],
            'sizes' => [
                'database_bytes' => $null ? null : 569_851_904,
                'docker_bytes' => $null ? null : ($scenario === 'warning' ? 21_474_836_480 : 928_500_000),
            ],
            'cert' => [
                'days_remaining' => match ($scenario) {
                    'critical' => 4,
                    'warning' => 18,
                    'unknown' => null,
                    default => 84,
                },
                'state' => match ($scenario) {
                    'critical', 'warning' => 'breached',
                    'unknown' => 'unknown',
                    default => 'ok',
                },
            ],
            'failed_units' => match ($scenario) {
                'critical' => ['some-broken.service'],
                'unknown' => null,
                default => [],
            },
            'reboot_required' => in_array($scenario, ['warning', 'critical'], true),
            'reboot_detail' => in_array($scenario, ['warning', 'critical'], true) ? 'REQUIRED (linux-image-generic)' : 'not required',
            'thresholds' => [
                'disk_warn_pct' => 80,
                'disk_crit_pct' => 90,
                'mem_target_pct' => 90,
                'cert_warn_days' => 21,
            ],
        ];

        File::put($path, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    private function printEnv(string $root): void
    {
        $bin = $root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.(str_starts_with(PHP_OS_FAMILY, 'Win') ? 'systemctl.bat' : 'systemctl');

        $this->line('Add to your .env, then run: php artisan config:clear');
        $this->newLine();

        foreach ([
            'VPS_PROC_PATH' => $root.DIRECTORY_SEPARATOR.'proc',
            'VPS_SNAPSHOT_PATH' => $root.DIRECTORY_SEPARATOR.'status.json',
            'VPS_REFRESH_REQUEST_PATH' => $root.DIRECTORY_SEPARATOR.'requests'.DIRECTORY_SEPARATOR.'refresh',
            'VPS_SYSTEMCTL_BIN' => $bin,
            'VPS_DISK_MOUNT' => str_starts_with(PHP_OS_FAMILY, 'Win') ? 'C:\\' : '/',
        ] as $key => $value) {
            $this->line(sprintf('%s="%s"', $key, $value));
        }

        $this->newLine();
        $this->comment('Disk is read from the real filesystem and cannot be faked. To exercise the disk warnings,');
        $this->comment('set VPS_DISK_WARN_PCT / VPS_DISK_CRIT_PCT below your actual usage instead.');
    }

    private function watch(string $root, string $scenario): void
    {
        $request = $root.'/requests/refresh';

        $until = now()->addMinutes(max(1, (int) $this->option('minutes')));

        $this->info(sprintf(
            'Watching until %s. CPU counters advance every second; refresh requests are serviced on sight. Ctrl+C to stop early.',
            $until->toTimeString(),
        ));

        while (now()->lessThan($until)) {
            $this->writeProc($root, $scenario);

            if (File::exists($request)) {
                File::delete($request);

                sleep(2);

                $this->writeSnapshot($root, $scenario);

                $this->line(sprintf('[%s] refresh request serviced', now()->toTimeString()));
            }

            sleep(1);
        }
    }
}
