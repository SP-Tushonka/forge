<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\Enums\VpsHealthStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Reads the health of the host this application is running on.
 */
final class VpsHealthService
{
    private const string SNAPSHOT_CACHE_KEY = 'vps-health:snapshot';

    private const string CPU_SAMPLE_CACHE_KEY = 'vps-health:cpu-sample';

    private const string SERVICE_CACHE_KEY = 'vps-health:services';

    /**
     * @var array{total_kb: int|null, available_kb: int|null, used_kb: int|null, used_pct: float|null, swap_total_kb: int|null, swap_free_kb: int|null, swap_used_kb: int|null, swap_used_pct: float|null}|null
     */
    private ?array $memory = null;

    /**
     * @var array{total_bytes: int|null, free_bytes: int|null, used_bytes: int|null, used_pct: float|null}|null
     */
    private ?array $disk = null;

    /**
     * @var array{load1: float|null, load5: float|null, load15: float|null, cpu_count: int|null, load_per_cpu: float|null}|null
     */
    private ?array $load = null;

    /**
     * @var list<array{unit: string, state: string|null, status: VpsHealthStatus}>|null
     */
    private ?array $services = null;

    /**
     * @var array{available: bool, usable: bool, stale: bool, generated_at: int|null, generated_at_utc: CarbonImmutable|null, age_seconds: int|null, error: string|null, data: array<string, mixed>}|null
     */
    private ?array $snapshot = null;

    private bool $cpuSampled = false;

    private ?float $cpuPct = null;

    private bool $failedJobsQueried = false;

    private ?int $failedJobs = null;

    /**
     * Live memory and swap. Usage is derived from MemAvailable rather than MemFree, so page cache is not counted
     * against the host.
     *
     * @return array{total_kb: int|null, available_kb: int|null, used_kb: int|null, used_pct: float|null, swap_total_kb: int|null, swap_free_kb: int|null, swap_used_kb: int|null, swap_used_pct: float|null}
     */
    public function memory(): array
    {
        return $this->memory ??= $this->readMemory();
    }

    /**
     * Live usage of the filesystem the application is installed on.
     *
     * @return array{total_bytes: int|null, free_bytes: int|null, used_bytes: int|null, used_pct: float|null}
     */
    public function disk(): array
    {
        return $this->disk ??= $this->readDisk();
    }

    /**
     * Live load averages, alongside the core count they should be read against.
     *
     * @return array{load1: float|null, load5: float|null, load15: float|null, cpu_count: int|null, load_per_cpu: float|null}
     */
    public function load(): array
    {
        return $this->load ??= $this->readLoad();
    }

    /**
     * CPU utilisation since the previous poll, or null when no usable earlier sample exists.
     */
    public function cpuUsagePct(): ?float
    {
        if ($this->cpuSampled) {
            return $this->cpuPct;
        }

        $this->cpuSampled = true;

        return $this->cpuPct = $this->readCpuUsagePct();
    }

    /**
     * How long the host has been up, in seconds.
     */
    public function uptimeSeconds(): ?int
    {
        $contents = $this->readProc('uptime');

        if ($contents === null) {
            return null;
        }

        $parts = preg_split('/\s+/', mb_trim($contents));
        $first = is_array($parts) ? ($parts[0] ?? null) : null;

        return is_string($first) && is_numeric($first) ? (int) (float) $first : null;
    }

    /**
     * The state of every monitored systemd unit, in configured order.
     *
     * @return list<array{unit: string, state: string|null, status: VpsHealthStatus}>
     */
    public function services(): array
    {
        return $this->services ??= $this->readServices();
    }

    /**
     * The published snapshot, with everything a caller needs to decide how much to trust it.
     *
     * @return array{available: bool, usable: bool, stale: bool, generated_at: int|null, generated_at_utc: CarbonImmutable|null, age_seconds: int|null, error: string|null, data: array<string, mixed>}
     */
    public function snapshot(): array
    {
        return $this->snapshot ??= $this->readSnapshot();
    }

    /**
     * Drop both the in-process and cached copies of the snapshot, so the next read hits the file again. Used after
     * requesting a refresh, where waiting out the cache window would hide the very change being waited for.
     */
    public function forgetSnapshot(): void
    {
        $this->snapshot = null;

        Cache::forget(self::SNAPSHOT_CACHE_KEY);
    }

    /**
     * Drop every cached reading, so the next call re-measures from scratch.
     */
    public function forgetCaches(): void
    {
        $this->forgetSnapshot();

        $this->services = null;
        $this->cpuSampled = false;
        $this->cpuPct = null;

        Cache::forget(self::CPU_SAMPLE_CACHE_KEY);
        Cache::forget(self::SERVICE_CACHE_KEY.':'.md5(implode(',', $this->monitoredUnits())));
    }

    /**
     * The generation timestamp of the document on disk right now, read past the shared cache and without disturbing it.
     */
    public function publishedAt(): ?int
    {
        $generatedAt = data_get($this->loadSnapshotFile()['data'], 'generated_at');

        return is_numeric($generatedAt) ? (int) $generatedAt : null;
    }

    /**
     * Ask the privileged timer for an out-of-band health check by dropping a request file in the directory it watches.
     */
    public function requestRefresh(): bool
    {
        $path = config()->string('vps.refresh_request_path');

        if ($path === '') {
            return false;
        }

        return rescue(static fn (): bool => touch($path), false, false) === true;
    }

    /**
     * The host name the snapshot was taken on.
     */
    public function host(): ?string
    {
        return $this->snapshotString('host');
    }

    /**
     * Yesterday's aggregates, covering a completed day.
     *
     * @return array{covers: string|null, cpu_avg_pct: float|null, cpu_peak_pct: float|null, cpu_peak_at: string|null, iowait_avg_pct: float|null, mem_peak_pct: float|null, mem_peak_at: string|null, mem_avail_min_kb: float|null, load_avg: float|null, load_peak: float|null, load_peak_at: string|null, swapout_avg: float|null}
     */
    public function yesterday(): array
    {
        return [
            'covers' => $this->snapshotString('yesterday.covers'),
            'cpu_avg_pct' => $this->snapshotFloat('yesterday.cpu_avg_pct'),
            'cpu_peak_pct' => $this->snapshotFloat('yesterday.cpu_peak_pct'),
            'cpu_peak_at' => $this->snapshotString('yesterday.cpu_peak_at'),
            'iowait_avg_pct' => $this->snapshotFloat('yesterday.iowait_avg_pct'),
            'mem_peak_pct' => $this->snapshotFloat('yesterday.mem_peak_pct'),
            'mem_peak_at' => $this->snapshotString('yesterday.mem_peak_at'),
            'mem_avail_min_kb' => $this->snapshotFloat('yesterday.mem_avail_min_kb'),
            'load_avg' => $this->snapshotFloat('yesterday.load_avg'),
            'load_peak' => $this->snapshotFloat('yesterday.load_peak'),
            'load_peak_at' => $this->snapshotString('yesterday.load_peak_at'),
            'swapout_avg' => $this->snapshotFloat('yesterday.swapout_avg'),
        ];
    }

    /**
     * Growth projections. Slopes stay null until the history spans enough days to fit a line through, which means a
     * freshly deployed host reports "unknown" rather than "fine".
     *
     * @return array{window_days: int|null, min_span_days: int, disk: array{slope_kb_per_day: float|null, days_to_target: float|null, state: string, samples: int|null, span_days: float|null}, memory: array{slope_pct_per_day: float|null, days_to_target: float|null, state: string}, database: array{slope_bytes_per_day: float|null}}
     */
    public function trends(): array
    {
        return [
            'window_days' => $this->snapshotInt('trends.window_days'),
            'min_span_days' => $this->minSpanDays(),
            'disk' => [
                'slope_kb_per_day' => $this->snapshotFloat('trends.disk.slope_kb_per_day'),
                'days_to_target' => $this->snapshotFloat('trends.disk.days_to_target'),
                'state' => $this->snapshotState('trends.disk.state'),
                'samples' => $this->snapshotInt('trends.disk.samples'),
                'span_days' => $this->snapshotFloat('trends.disk.span_days'),
            ],
            'memory' => [
                'slope_pct_per_day' => $this->snapshotFloat('trends.memory.slope_pct_per_day'),
                'days_to_target' => $this->snapshotFloat('trends.memory.days_to_target'),
                'state' => $this->snapshotState('trends.memory.state'),
            ],
            'database' => [
                'slope_bytes_per_day' => $this->snapshotFloat('trends.database.slope_bytes_per_day'),
            ],
        ];
    }

    /**
     * On-disk footprint of the things that grow on their own.
     *
     * @return array{database_bytes: int|null, docker_bytes: int|null}
     */
    public function sizes(): array
    {
        return [
            'database_bytes' => $this->snapshotInt('sizes.database_bytes'),
            'docker_bytes' => $this->snapshotInt('sizes.docker_bytes'),
        ];
    }

    /**
     * TLS certificate expiry.
     *
     * @return array{days_remaining: int|null, state: string}
     */
    public function cert(): array
    {
        return [
            'days_remaining' => $this->snapshotInt('cert.days_remaining'),
            'state' => $this->snapshotState('cert.state'),
        ];
    }

    /**
     * Systemd units the host reports as failed, or null when that list was never measured.
     *
     * @return list<string>|null
     */
    public function failedUnits(): ?array
    {
        if (! $this->snapshot()['usable']) {
            return null;
        }

        $units = data_get($this->snapshot()['data'], 'failed_units');

        if (! is_array($units)) {
            return null;
        }

        $names = [];

        foreach ($units as $unit) {
            if (! is_string($unit) || mb_trim($unit) === '') {
                return null;
            }

            $names[] = mb_trim($unit);
        }

        return $names;
    }

    /**
     * Whether the host is waiting on a reboot, and why.
     *
     * @return array{required: bool|null, detail: string|null}
     */
    public function reboot(): array
    {
        $required = data_get($this->snapshot()['data'], 'reboot_required');

        return [
            'required' => is_bool($required) ? $required : null,
            'detail' => $this->snapshotString('reboot_detail'),
        ];
    }

    /**
     * The number of jobs sitting in the failed queue, or null when the table cannot be read.
     */
    public function failedJobs(): ?int
    {
        if ($this->failedJobsQueried) {
            return $this->failedJobs;
        }

        $this->failedJobsQueried = true;

        $table = config()->string('queue.failed.table', 'failed_jobs');

        if ($table === '') {
            return null;
        }

        return $this->failedJobs = rescue(
            static fn (): int => DB::table($table)->count(),
            null,
            false,
        );
    }

    /**
     * The thresholds every rule is judged against.
     *
     * @return array{disk_warn_pct: float, disk_crit_pct: float, mem_warn_pct: float, mem_crit_pct: float, mem_target_pct: float, swap_warn_pct: float, cert_warn_days: int, cert_crit_days: int, load_per_cpu_warn: float, trend_warn_days: int, docker_warn_bytes: int}
     */
    public function thresholds(): array
    {
        return [
            'disk_warn_pct' => $this->threshold('disk_warn_pct'),
            'disk_crit_pct' => $this->threshold('disk_crit_pct'),
            'mem_warn_pct' => $this->threshold('mem_warn_pct'),
            'mem_crit_pct' => $this->threshold('mem_crit_pct'),
            'mem_target_pct' => $this->threshold('mem_target_pct'),
            'swap_warn_pct' => $this->threshold('swap_warn_pct'),
            'cert_warn_days' => (int) $this->threshold('cert_warn_days'),
            'cert_crit_days' => (int) $this->threshold('cert_crit_days'),
            'load_per_cpu_warn' => $this->threshold('load_per_cpu_warn'),
            'trend_warn_days' => (int) $this->threshold('trend_warn_days'),
            'docker_warn_bytes' => (int) $this->threshold('docker_warn_bytes'),
        ];
    }

    /**
     * @return array{total_kb: int|null, available_kb: int|null, used_kb: int|null, used_pct: float|null, swap_total_kb: int|null, swap_free_kb: int|null, swap_used_kb: int|null, swap_used_pct: float|null}
     */
    private function readMemory(): array
    {
        $fields = $this->readMeminfo();

        $total = $fields['MemTotal'] ?? null;
        $available = $fields['MemAvailable'] ?? null;
        $swapTotal = $fields['SwapTotal'] ?? null;
        $swapFree = $fields['SwapFree'] ?? null;

        $used = $total !== null && $available !== null ? max($total - $available, 0) : null;
        $swapUsed = $swapTotal !== null && $swapFree !== null ? max($swapTotal - $swapFree, 0) : null;

        return [
            'total_kb' => $total,
            'available_kb' => $available,
            'used_kb' => $used,
            'used_pct' => $this->share($used, $total),
            'swap_total_kb' => $swapTotal,
            'swap_free_kb' => $swapFree,
            'swap_used_kb' => $swapUsed,
            'swap_used_pct' => $this->share($swapUsed, $swapTotal),
        ];
    }

    /**
     * A part as a percentage of its whole, or null when either side went unmeasured.
     */
    private function share(?int $part, ?int $whole): ?float
    {
        if ($part === null || $whole === null || $whole <= 0) {
            return null;
        }

        return ($part / $whole) * 100;
    }

    /**
     * @return array<string, int>
     */
    private function readMeminfo(): array
    {
        $contents = $this->readProc('meminfo');

        if ($contents === null) {
            return [];
        }

        preg_match_all('/^(\w+):\s+(\d+)\s+kB$/m', $contents, $matches, PREG_SET_ORDER);

        $fields = [];

        foreach ($matches as $match) {
            $fields[$match[1]] = (int) $match[2];
        }

        return $fields;
    }

    /**
     * @return array{total_bytes: int|null, free_bytes: int|null, used_bytes: int|null, used_pct: float|null}
     */
    private function readDisk(): array
    {
        $mount = config()->string('vps.disk_mount', '/');

        $total = rescue(static fn (): float|false => disk_total_space($mount), false, false);
        $free = rescue(static fn (): float|false => disk_free_space($mount), false, false);

        $totalBytes = is_float($total) && $total > 0 ? (int) $total : null;
        $freeBytes = is_float($free) && $free >= 0 ? (int) $free : null;
        $usedBytes = $totalBytes !== null && $freeBytes !== null ? max($totalBytes - $freeBytes, 0) : null;

        return [
            'total_bytes' => $totalBytes,
            'free_bytes' => $freeBytes,
            'used_bytes' => $usedBytes,
            'used_pct' => $this->share($usedBytes, $totalBytes),
        ];
    }

    /**
     * @return array{load1: float|null, load5: float|null, load15: float|null, cpu_count: int|null, load_per_cpu: float|null}
     */
    private function readLoad(): array
    {
        $contents = $this->readProc('loadavg');
        $parts = $contents === null ? [] : preg_split('/\s+/', mb_trim($contents));
        $values = is_array($parts) ? $parts : [];

        $load1 = isset($values[0]) && is_numeric($values[0]) ? (float) $values[0] : null;
        $cpuCount = $this->cpuCount();

        return [
            'load1' => $load1,
            'load5' => isset($values[1]) && is_numeric($values[1]) ? (float) $values[1] : null,
            'load15' => isset($values[2]) && is_numeric($values[2]) ? (float) $values[2] : null,
            'cpu_count' => $cpuCount,
            'load_per_cpu' => $load1 !== null && $cpuCount !== null && $cpuCount > 0 ? $load1 / $cpuCount : null,
        ];
    }

    /**
     * The number of logical cores, counted from the per-core lines in /proc/stat.
     */
    private function cpuCount(): ?int
    {
        $contents = $this->readProc('stat');

        if ($contents === null) {
            return null;
        }

        $count = preg_match_all('/^cpu\d+\s/m', $contents);

        return is_int($count) && $count > 0 ? $count : null;
    }

    private function readCpuUsagePct(): ?float
    {
        $sample = $this->readCpuSample();

        if ($sample === null) {
            return null;
        }

        $previous = Cache::get(self::CPU_SAMPLE_CACHE_KEY);

        Cache::put(self::CPU_SAMPLE_CACHE_KEY, $sample, now()->addSeconds(config()->integer('vps.cpu_sample_max_age_seconds')));

        if (! is_array($previous) || ! isset($previous['at'], $previous['total'], $previous['idle'])) {
            return null;
        }

        /** @var array{at: int, total: int, idle: int} $previous */
        $elapsed = $sample['at'] - $previous['at'];

        if ($elapsed <= 0 || $elapsed > config()->integer('vps.cpu_sample_max_age_seconds')) {
            return null;
        }

        $totalDelta = $sample['total'] - $previous['total'];
        $idleDelta = $sample['idle'] - $previous['idle'];

        if ($totalDelta <= 0) {
            return null;
        }

        return max(0.0, min(100.0, (1 - ($idleDelta / $totalDelta)) * 100));
    }

    /**
     * The aggregate CPU line of /proc/stat, reduced to busy-versus-idle jiffies. Idle counts iowait, so a host stalled
     * on disk does not read as busy.
     *
     * @return array{at: int, total: int, idle: int}|null
     */
    private function readCpuSample(): ?array
    {
        $contents = $this->readProc('stat');

        if ($contents === null || preg_match('/^cpu\s+(.+)$/m', $contents, $matches) !== 1) {
            return null;
        }

        $fields = preg_split('/\s+/', mb_trim($matches[1]));

        if (! is_array($fields) || count($fields) < 5) {
            return null;
        }

        $total = 0;

        foreach (array_slice($fields, 0, 8) as $field) {
            if (! is_numeric($field)) {
                return null;
            }

            $total += (int) $field;
        }

        return [
            'at' => now()->getTimestamp(),
            'total' => $total,
            'idle' => (int) $fields[3] + (int) $fields[4],
        ];
    }

    /**
     * @return list<array{unit: string, state: string|null, status: VpsHealthStatus}>
     */
    private function readServices(): array
    {
        $units = $this->monitoredUnits();

        if ($units === []) {
            return [];
        }

        $states = Cache::remember(
            self::SERVICE_CACHE_KEY.':'.md5(implode(',', $units)),
            now()->addSeconds(config()->integer('vps.service_cache_seconds')),
            fn (): array => $this->probeUnits($units),
        );

        return array_map(function (string $unit) use ($states): array {
            $state = $states[$unit] ?? null;

            return [
                'unit' => $unit,
                'state' => $state,
                'status' => $this->unitStatus($state),
            ];
        }, $units);
    }

    /**
     * Start every probe, then collect each one on its own.
     *
     * @param  list<string>  $units
     * @return array<string, string|null>
     */
    private function probeUnits(array $units): array
    {
        $timeout = config()->integer('vps.service_timeout', 2);
        $started = [];

        foreach ($units as $unit) {
            try {
                $started[$unit] = Process::timeout($timeout)->start([config()->string('vps.systemctl_bin'), 'is-active', $unit]);
            } catch (Throwable) {
                $started[$unit] = null;
            }
        }

        $states = [];

        foreach ($units as $unit) {
            $states[$unit] = $this->awaitState($started[$unit]);
        }

        return $states;
    }

    private function awaitState(?InvokedProcess $process): ?string
    {
        if ($process === null) {
            return null;
        }

        try {
            $result = $process->wait();
        } catch (Throwable) {
            return null;
        }

        // systemctl exits non-zero for anything but "active" while still printing the state, so the output is read
        // regardless of the exit code and only a genuinely silent probe counts as unknown.
        $output = mb_trim($result->output());

        return $output === '' ? null : $output;
    }

    /**
     * How a reported unit state should be read.
     */
    private function unitStatus(?string $state): VpsHealthStatus
    {
        return match ($state) {
            null => VpsHealthStatus::Unknown,
            'active' => VpsHealthStatus::Ok,
            'activating', 'deactivating', 'reloading', 'refreshing', 'maintenance' => VpsHealthStatus::Warning,
            default => VpsHealthStatus::Critical,
        };
    }

    /**
     * @return list<string>
     */
    private function monitoredUnits(): array
    {
        $units = config('vps.services');

        if (! is_array($units)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $unit): string => is_string($unit) ? mb_trim($unit) : '', $units),
            static fn (string $unit): bool => $unit !== '',
        ));
    }

    /**
     * @return array{available: bool, usable: bool, stale: bool, generated_at: int|null, generated_at_utc: CarbonImmutable|null, age_seconds: int|null, error: string|null, data: array<string, mixed>}
     */
    private function readSnapshot(): array
    {
        $document = Cache::remember(
            self::SNAPSHOT_CACHE_KEY,
            now()->addSeconds(config()->integer('vps.snapshot_cache_seconds')),
            fn (): array => $this->loadSnapshotFile(),
        );

        $data = $document['data'];

        if ($data === null) {
            return [
                'available' => false,
                'usable' => false,
                'stale' => true,
                'generated_at' => null,
                'generated_at_utc' => null,
                'age_seconds' => null,
                'error' => $document['error'] ?? 'The health snapshot could not be read.',
                'data' => [],
            ];
        }

        $generatedAt = data_get($data, 'generated_at');
        $generatedAt = is_numeric($generatedAt) ? (int) $generatedAt : null;
        $age = $generatedAt === null ? null : max(now()->getTimestamp() - $generatedAt, 0);
        $stale = $age === null || $age > config()->integer('vps.snapshot_max_age_seconds');

        return [
            'available' => true,
            'usable' => ! $stale,
            'stale' => $stale,
            'generated_at' => $generatedAt,
            'generated_at_utc' => $generatedAt === null ? null : CarbonImmutable::createFromTimestampUTC($generatedAt),
            'age_seconds' => $age,
            'error' => $generatedAt === null ? 'The health snapshot carries no generation timestamp.' : null,
            'data' => $data,
        ];
    }

    /**
     * The errors describe what went wrong without naming the file. Filesystem layout is not something a browser needs
     * to be told, and a screenshot of this page should not be a map of the host.
     *
     * @return array{data: array<string, mixed>|null, error: string|null}
     */
    private function loadSnapshotFile(): array
    {
        $path = config()->string('vps.snapshot_path');

        if ($path === '' || ! is_readable($path)) {
            return ['data' => null, 'error' => 'No health snapshot has been published yet, or the file it is published to is not readable.'];
        }

        $contents = rescue(static fn (): string|false => file_get_contents($path), false, false);

        if (! is_string($contents) || $contents === '') {
            return ['data' => null, 'error' => 'The health snapshot exists but is empty or could not be read.'];
        }

        $decoded = rescue(static fn (): mixed => json_decode($contents, true, 512, JSON_THROW_ON_ERROR), null, false);

        if (! is_array($decoded)) {
            return ['data' => null, 'error' => 'The health snapshot is not valid JSON.'];
        }

        /** @var array<string, mixed> $decoded */
        return ['data' => $decoded, 'error' => null];
    }

    private function threshold(string $key): float
    {
        $published = $this->publishedPolicy('thresholds.'.$key);

        if (is_numeric($published)) {
            return (float) $published;
        }

        $local = config('vps.thresholds.'.$key);

        return is_numeric($local) ? (float) $local : 0.0;
    }

    /**
     * The history a trend needs before a slope is fitted to it. A policy value rather than a measurement, so it always
     * resolves: the snapshot's while that snapshot is usable, the local configuration otherwise.
     */
    private function minSpanDays(): int
    {
        $published = $this->publishedPolicy('trends.min_span_days');

        return is_numeric($published) ? (int) $published : config()->integer('vps.trends.min_span_days');
    }

    /**
     * A policy value the snapshot may publish, or null when it may not be trusted to. Only policy - never a reading -
     * is resolved this way: readings stay null when they are missing, and are shown as stale when they are old.
     */
    private function publishedPolicy(string $path): mixed
    {
        $snapshot = $this->snapshot();

        return $snapshot['usable'] ? data_get($snapshot['data'], $path) : null;
    }

    private function snapshotFloat(string $path): ?float
    {
        $value = data_get($this->snapshot()['data'], $path);

        return is_numeric($value) ? (float) $value : null;
    }

    private function snapshotInt(string $path): ?int
    {
        $value = data_get($this->snapshot()['data'], $path);

        return is_numeric($value) ? (int) $value : null;
    }

    private function snapshotString(string $path): ?string
    {
        $value = data_get($this->snapshot()['data'], $path);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A published state field, collapsed to the vocabulary the snapshot uses. Anything unrecognised is "unknown", so a
     * malformed document cannot read as healthy.
     */
    private function snapshotState(string $path): string
    {
        $value = $this->snapshotString($path);

        return in_array($value, ['ok', 'breached'], true) ? $value : 'unknown';
    }

    private function readProc(string $file): ?string
    {
        $base = config()->string('vps.proc_path', '/proc');

        if ($base === '') {
            return null;
        }

        $path = mb_rtrim($base, '/').'/'.$file;

        if (! is_readable($path)) {
            return null;
        }

        $contents = rescue(static fn (): string|false => file_get_contents($path), false, false);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
