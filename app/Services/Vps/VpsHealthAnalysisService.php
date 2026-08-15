<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Enums\VpsHealthStatus;
use App\Support\DataTransferObjects\VpsHealthFinding;
use Illuminate\Support\Number;

/**
 * Turns the readings into findings: what is wrong, the measurement that says so, and what to do about it.
 */
final class VpsHealthAnalysisService
{
    /**
     * @var list<VpsHealthFinding>|null
     */
    private ?array $findings = null;

    public function __construct(private readonly VpsHealthService $health) {}

    /**
     * Every finding, worst first.
     *
     * @return list<VpsHealthFinding>
     */
    public function findings(): array
    {
        if ($this->findings !== null) {
            return $this->findings;
        }

        $findings = [
            ...$this->serviceFindings(),
            ...$this->diskFindings(),
            ...$this->memoryFindings(),
            ...$this->loadFindings(),
            ...$this->queueFindings(),
            ...$this->snapshotFindings(),
            ...$this->unreadableFindings(),
            ...$this->unmeasuredSnapshotFindings(),
        ];

        usort($findings, static fn (VpsHealthFinding $a, VpsHealthFinding $b): int => $b->severity->rank() <=> $a->severity->rank());

        return $this->findings = $findings;
    }

    /**
     * The findings that need acting on now.
     *
     * @return list<VpsHealthFinding>
     */
    public function immediate(): array
    {
        return array_values(array_filter(
            $this->findings(),
            static fn (VpsHealthFinding $finding): bool => $finding->severity === VpsHealthStatus::Critical,
        ));
    }

    /**
     * The findings worth scheduling, but not worth waking anyone for.
     *
     * @return list<VpsHealthFinding>
     */
    public function recommended(): array
    {
        return array_values(array_filter(
            $this->findings(),
            static fn (VpsHealthFinding $finding): bool => $finding->severity === VpsHealthStatus::Warning,
        ));
    }

    /**
     * The overall verdict: the worst finding, or healthy when there are none.
     */
    public function verdict(): VpsHealthStatus
    {
        return VpsHealthStatus::worst(array_map(
            static fn (VpsHealthFinding $finding): VpsHealthStatus => $finding->severity,
            $this->findings(),
        ));
    }

    /**
     * A one-line summary of the verdict for the top of the page.
     */
    public function headline(): string
    {
        $immediate = count($this->immediate());
        $recommended = count($this->recommended());

        if ($immediate > 0) {
            return $immediate === 1
                ? 'One issue needs attention now.'
                : $immediate.' issues need attention now.';
        }

        if ($recommended > 0) {
            return $recommended === 1
                ? 'Nothing is broken. One item is worth scheduling.'
                : 'Nothing is broken. '.$recommended.' items are worth scheduling.';
        }

        return 'Everything checked is healthy. No action needed.';
    }

    /**
     * @return list<VpsHealthFinding>
     */
    private function serviceFindings(): array
    {
        $findings = [];

        foreach ($this->health->services() as $service) {
            if ($service['status'] === VpsHealthStatus::Critical) {
                $findings[] = VpsHealthFinding::critical(
                    $service['unit'].' is not running',
                    'systemctl reports '.$service['unit'].' as "'.($service['state'] ?? 'unknown').'".',
                    'Run `systemctl status '.$service['unit'].'` and `journalctl -u '.$service['unit'].' -n 100` to find out why it stopped, then start it.',
                );
            }

            if ($service['status'] === VpsHealthStatus::Warning) {
                $findings[] = VpsHealthFinding::warning(
                    $service['unit'].' is changing state',
                    'systemctl reports '.$service['unit'].' as "'.($service['state'] ?? 'unknown').'", which is what a restart looks like while it is happening.',
                    'Nothing to do if a deploy or a restart is in flight. If it is still saying this in a minute, check `systemctl status '.$service['unit'].'`.',
                );
            }
        }

        if (! $this->health->snapshot()['usable']) {
            return $findings;
        }

        $failed = $this->health->failedUnits();

        if ($failed !== null && $failed !== []) {
            $findings[] = VpsHealthFinding::critical(
                count($failed).' systemd '.(count($failed) === 1 ? 'unit has' : 'units have').' failed',
                'The host reports these units in a failed state: '.implode(', ', $failed).'.',
                'Inspect each with `journalctl -u <unit> -n 100`, fix the cause, then `systemctl reset-failed`.',
            );
        }

        return $findings;
    }

    /**
     * @return list<VpsHealthFinding>
     */
    private function diskFindings(): array
    {
        $thresholds = $this->health->thresholds();
        $disk = $this->health->disk();
        $used = $disk['used_pct'];
        $findings = [];

        if ($used !== null && $used >= $thresholds['disk_crit_pct']) {
            $findings[] = VpsHealthFinding::critical(
                'Disk is critically full',
                $this->percent($used).' of the root filesystem is in use, past the '.$this->percent($thresholds['disk_crit_pct']).' critical threshold. '.$this->bytes($disk['free_bytes']).' remains free.',
                'Free space now: clear old verification artefacts, rotate logs, and prune Docker with `docker system prune -a`.',
            );
        } elseif ($used !== null && $used >= $thresholds['disk_warn_pct']) {
            $findings[] = VpsHealthFinding::warning(
                'Disk is filling up',
                $this->percent($used).' of the root filesystem is in use, past the '.$this->percent($thresholds['disk_warn_pct']).' warning threshold. '.$this->bytes($disk['free_bytes']).' remains free.',
                'Schedule a clean-up before it reaches '.$this->percent($thresholds['disk_crit_pct']).': prune Docker images and old artefacts.',
            );
        }

        if (! $this->health->snapshot()['usable']) {
            return $findings;
        }

        $trend = $this->health->trends()['disk'];
        $days = $trend['days_to_target'];

        if ($days !== null && $days <= $thresholds['trend_warn_days']) {
            $findings[] = VpsHealthFinding::warning(
                'Disk is trending toward full',
                'At the current growth rate the disk reaches its target threshold in about '.$this->days($days).', measured over the last '.($trend['span_days'] !== null ? $this->days($trend['span_days']) : 'sampled window').'.',
                'Find what is growing with a disk usage summary of the root filesystem and `docker system df`, reclaim it, or plan more storage.',
            );
        }

        $docker = $this->health->sizes()['docker_bytes'];

        if ($docker !== null && $docker > $thresholds['docker_warn_bytes']) {
            $findings[] = VpsHealthFinding::warning(
                'Docker is using a lot of disk',
                'Docker data is '.$this->bytes($docker).', past the '.$this->bytes($thresholds['docker_warn_bytes']).' advisory limit.',
                'Reclaim it with `docker system prune -a --volumes` once no verification container is running.',
            );
        }

        return $findings;
    }

    /**
     * @return list<VpsHealthFinding>
     */
    private function memoryFindings(): array
    {
        $thresholds = $this->health->thresholds();
        $memory = $this->health->memory();
        $used = $memory['used_pct'];
        $findings = [];

        if ($used !== null && $used >= $thresholds['mem_crit_pct']) {
            $findings[] = VpsHealthFinding::critical(
                'Memory is exhausted',
                $this->percent($used).' of RAM is in use with only '.$this->kilobytes($memory['available_kb']).' available. The kernel is close to killing processes to reclaim memory.',
                'Check what is consuming it (`ps aux --sort=-rss | head`) and restart the offender; reduce Horizon worker counts if the queue is the cause.',
            );
        } elseif ($used !== null && $used >= $thresholds['mem_warn_pct']) {
            $findings[] = VpsHealthFinding::warning(
                'Memory is running high',
                $this->percent($used).' of RAM is in use, with '.$this->kilobytes($memory['available_kb']).' available.',
                'Keep an eye on it. If it stays here, review Horizon worker counts and PHP-FPM pool sizes.',
            );
        }

        $swap = $memory['swap_used_pct'];

        if ($swap !== null && $swap > $thresholds['swap_warn_pct']) {
            $findings[] = VpsHealthFinding::warning(
                'Swap is in use',
                $this->kilobytes($memory['swap_used_kb']).' of swap is in use ('.$this->percent($swap).' of the swap device). Swapping slows every request that touches it.',
                'Identify the process that ballooned, restart it, and consider `swapoff -a && swapon -a` once memory pressure has passed.',
            );
        }

        if (! $this->health->snapshot()['usable']) {
            return $findings;
        }

        $days = $this->health->trends()['memory']['days_to_target'];

        if ($days !== null && $days <= $thresholds['trend_warn_days']) {
            $findings[] = VpsHealthFinding::warning(
                'Memory use is trending upward',
                'At the current rate memory reaches '.$this->percent($thresholds['mem_target_pct']).' in about '.$this->days($days).'.',
                'Look for a leak in the long-running workers; restarting Horizon on a schedule is the usual stopgap.',
            );
        }

        return $findings;
    }

    /**
     * @return list<VpsHealthFinding>
     */
    private function loadFindings(): array
    {
        $load = $this->health->load();
        $perCpu = $load['load_per_cpu'];
        $load1 = $load['load1'];
        $cpuCount = $load['cpu_count'];

        if ($perCpu === null || $load1 === null || $cpuCount === null) {
            return [];
        }

        if ($perCpu < $this->health->thresholds()['load_per_cpu_warn']) {
            return [];
        }

        return [VpsHealthFinding::warning(
            'Load average is above the core count',
            'The one-minute load average is '.number_format($load1, 2).' across '.$cpuCount.' cores, or '.number_format($perCpu, 2).' per core.',
            'Check what is queued for CPU or blocked on disk with `top` and `iostat`; a long verification run is the usual explanation.',
        )];
    }

    /**
     * @return list<VpsHealthFinding>
     */
    private function queueFindings(): array
    {
        $failed = $this->health->failedJobs();

        if ($failed === null) {
            return [VpsHealthFinding::warning(
                'The failed queue jobs could not be counted',
                'The failed job table could not be read, so the number of failed jobs is unknown rather than none.',
                'Check the database connection and that the configured failed job table exists.',
            )];
        }

        if ($failed === 0) {
            return [];
        }

        return [VpsHealthFinding::warning(
            $failed === 1 ? 'One failed queue job' : $failed.' failed queue jobs',
            'The failed job table holds '.number_format($failed).' '.($failed === 1 ? 'entry' : 'entries').'.',
            'Review them in Horizon, fix the cause, then retry or prune the batch.',
        )];
    }

    /**
     * @return list<VpsHealthFinding>
     */
    private function snapshotFindings(): array
    {
        $snapshot = $this->health->snapshot();

        if (! $snapshot['available']) {
            return [VpsHealthFinding::warning(
                'No health snapshot is available',
                $snapshot['error'] ?? 'The snapshot could not be read.',
                'Check the health timer on the host (`systemctl status forge-health.timer`). Until it publishes, certificate expiry, growth trends and Docker sizes are unknown.',
            )];
        }

        if ($snapshot['stale']) {
            return [VpsHealthFinding::warning(
                'The health snapshot is stale',
                'It was generated '.($snapshot['age_seconds'] !== null ? $this->duration($snapshot['age_seconds']).' ago' : 'at an unknown time').', past the '.$this->duration(config()->integer('vps.snapshot_max_age_seconds')).' limit. Certificate, trend and size figures are not being reported from it.',
                'Check the health timer on the host (`systemctl status forge-health.timer`), or request a check with the button above.',
            )];
        }

        $findings = [];
        $thresholds = $this->health->thresholds();
        $cert = $this->health->cert();
        $days = $cert['days_remaining'];

        if ($days !== null && $days <= $thresholds['cert_crit_days']) {
            $findings[] = VpsHealthFinding::critical(
                'TLS certificate expires imminently',
                'The certificate has '.$days.' '.($days === 1 ? 'day' : 'days').' left.',
                'Renew it now with `certbot renew --force-renewal` and reload nginx.',
            );
        } elseif ($days !== null && $days <= $thresholds['cert_warn_days']) {
            $findings[] = VpsHealthFinding::warning(
                'TLS certificate expires soon',
                'The certificate has '.$days.' days left, inside the '.$thresholds['cert_warn_days'].' day warning window.',
                'Confirm the renewal timer is armed with `systemctl status certbot.timer`; renewal normally happens on its own.',
            );
        }

        $reboot = $this->health->reboot();

        if ($reboot['required'] === true) {
            $findings[] = VpsHealthFinding::warning(
                'A reboot is pending',
                $reboot['detail'] ?? 'The host is waiting on a reboot to finish applying updates.',
                'Schedule a reboot outside peak hours. Until then some patched libraries are still running from their old copies.',
            );
        }

        return $findings;
    }

    /**
     * Readings that failed. Reported explicitly so that a missing measurement is never mistaken for a healthy one.
     *
     * @return list<VpsHealthFinding>
     */
    private function unreadableFindings(): array
    {
        $missing = [];

        if ($this->health->memory()['used_pct'] === null) {
            $missing[] = 'memory';
        }

        if ($this->health->disk()['used_pct'] === null) {
            $missing[] = 'disk';
        }

        if ($this->health->load()['load1'] === null) {
            $missing[] = 'load average';
        }

        $unknownUnits = array_filter(
            $this->health->services(),
            static fn (array $service): bool => $service['state'] === null,
        );

        if ($unknownUnits !== []) {
            $missing[] = 'service states';
        }

        if ($missing === []) {
            return [];
        }

        return [VpsHealthFinding::warning(
            'Some live readings could not be taken',
            'These are reported as unknown rather than healthy: '.implode(', ', $missing).'.',
            'Confirm the web user can still read the kernel process filesystem and run systemctl. A hardening change or a PHP upgrade resetting disable_functions is the usual cause.',
        )];
    }

    /**
     * Fields the last health check did not manage to collect, while that check is recent enough to draw conclusions
     * from otherwise.
     *
     * @return list<VpsHealthFinding>
     */
    private function unmeasuredSnapshotFindings(): array
    {
        if (! $this->health->snapshot()['usable']) {
            return [];
        }

        $missing = [];
        $sizes = $this->health->sizes();
        $trends = $this->health->trends();

        if ($this->health->cert()['days_remaining'] === null) {
            $missing[] = 'certificate expiry';
        }

        if ($sizes['database_bytes'] === null) {
            $missing[] = 'database size';
        }

        if ($sizes['docker_bytes'] === null) {
            $missing[] = 'Docker size';
        }

        if ($this->health->failedUnits() === null) {
            $missing[] = 'failed unit list';
        }

        if ($this->health->reboot()['required'] === null) {
            $missing[] = 'pending reboot';
        }

        if ($trends['disk']['state'] === 'unknown') {
            $missing[] = 'disk growth trend';
        }

        if ($trends['memory']['state'] === 'unknown') {
            $missing[] = 'memory growth trend';
        }

        if ($this->yesterdayWasNotAggregated()) {
            $missing[] = "yesterday's aggregates";
        }

        if ($missing === []) {
            return [];
        }

        return [VpsHealthFinding::warning(
            'The last health check did not collect everything',
            'It ran, but produced no reading for these, so they are reported as unknown rather than healthy: '.implode(', ', $missing).'.',
            'Check what the health check logged on its last run. Each of these points at a collection that failed rather than one still gathering history.',
        )];
    }

    /**
     * Whether yesterday's aggregates came back entirely empty, which is the shape a failed sar collection takes.
     */
    private function yesterdayWasNotAggregated(): bool
    {
        $yesterday = $this->health->yesterday();

        return array_filter([
            $yesterday['cpu_avg_pct'],
            $yesterday['cpu_peak_pct'],
            $yesterday['iowait_avg_pct'],
            $yesterday['mem_peak_pct'],
            $yesterday['mem_avail_min_kb'],
            $yesterday['load_avg'],
            $yesterday['load_peak'],
            $yesterday['swapout_avg'],
        ], static fn (?float $value): bool => $value !== null) === [];
    }

    private function percent(float $value): string
    {
        return number_format($value, 1).'%';
    }

    private function bytes(?int $value): string
    {
        return $value === null ? 'an unknown amount' : Number::fileSize($value, precision: 1);
    }

    private function kilobytes(?int $value): string
    {
        return $value === null ? 'an unknown amount' : Number::fileSize($value * 1024, precision: 1);
    }

    private function days(float $value): string
    {
        return $value < 1
            ? 'less than a day'
            : number_format($value, $value < 10 ? 1 : 0).' days';
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' seconds';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).' minutes';
        }

        return number_format($seconds / 3600, 1).' hours';
    }
}
