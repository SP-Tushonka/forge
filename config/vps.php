<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Health Snapshot
    |--------------------------------------------------------------------------
    |
    | Path to the JSON document a sibling shell timer publishes every 15 minutes
    | with everything the web user cannot read for itself (trend history in the
    | run user's home, Docker sizes, certificate expiry, failed units). The web
    | user only needs read access to this one file.
    |
    | The document is re-read from disk at most once per cache window so the
    | live poll does not hit the filesystem on every request. The staleness
    | limit is what turns an old snapshot into a reported problem: past it the
    | figures are no longer presented as current fact.
    |
    */

    'snapshot_path' => env('VPS_SNAPSHOT_PATH', '/var/lib/forge-health/status.json'),

    'snapshot_cache_seconds' => (int) env('VPS_SNAPSHOT_CACHE_SECONDS', 60),

    'snapshot_max_age_seconds' => (int) env('VPS_SNAPSHOT_MAX_AGE_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | On-Demand Refresh
    |--------------------------------------------------------------------------
    |
    | Creating this file asks a systemd path unit to run the health check out of
    | band; the unit deletes the request as it starts, which re-arms the watch.
    | The directory is group-writable for the web user, the snapshot itself is
    | not, so the web process can ask for a check but never forge its result.
    |
    | The check is asynchronous and takes a few seconds, so the dashboard waits
    | for the snapshot's generated_at to advance and gives up after the timeout
    | rather than showing a request that never landed as a success.
    |
    */

    'refresh_request_path' => env('VPS_REFRESH_REQUEST_PATH', '/var/lib/forge-health/requests/refresh'),

    'refresh_timeout_seconds' => (int) env('VPS_REFRESH_TIMEOUT_SECONDS', 30),

    'refresh_rate_limit_seconds' => (int) env('VPS_REFRESH_RATE_LIMIT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Kernel Interfaces
    |--------------------------------------------------------------------------
    |
    | Where the live readings come from. Overridable so tests can point at a
    | fixture directory instead of the real procfs.
    |
    */

    'proc_path' => env('VPS_PROC_PATH', '/proc'),

    'disk_mount' => env('VPS_DISK_MOUNT', '/'),

    /*
    |--------------------------------------------------------------------------
    | CPU Sampling
    |--------------------------------------------------------------------------
    |
    | CPU utilisation is a delta between two /proc/stat reads. The previous read
    | is cached and compared against on the next poll rather than sleeping mid
    | request. A sample older than this is discarded: the resulting figure would
    | be an average over a window far wider than "now".
    |
    */

    'cpu_sample_max_age_seconds' => (int) env('VPS_CPU_SAMPLE_MAX_AGE_SECONDS', 300),

    /*
    |--------------------------------------------------------------------------
    | Monitored Units
    |--------------------------------------------------------------------------
    |
    | The systemd units checked on every poll, matching the list the shell
    | monitoring watches. This is an allow-list: nothing outside it is ever
    | handed to systemctl, and no request input reaches the command line.
    |
    */

    'services' => [
        'nginx',
        'php8.5-fpm',
        'mysql',
        'redis-server',
        'forge-horizon',
        'forge-queue-verification',
        'forge-queue-long',
        'forge-reverb',
    ],

    /*
    | Overridable so a workstation with no systemd can point at the stub `vps:fixture` writes, and
    | so a distribution that puts systemctl somewhere other than the PATH default still works. It
    | is a binary path, never a shell string: unit names are appended as separate arguments.
    */

    'systemctl_bin' => env('VPS_SYSTEMCTL_BIN', 'systemctl'),

    'service_timeout' => (int) env('VPS_SERVICE_TIMEOUT', 2),

    'service_cache_seconds' => (int) env('VPS_SERVICE_CACHE_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Growth Trends
    |--------------------------------------------------------------------------
    |
    | How much history a trend needs before a slope is fitted to it. Until the
    | samples span this many days the trend is reported as unknown rather than
    | flat. Fallback only, on the same terms as the thresholds below.
    |
    */

    'trends' => [
        'min_span_days' => (int) env('VPS_TREND_MIN_SPAN_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Thresholds
    |--------------------------------------------------------------------------
    |
    | Fallbacks only. Where a usable snapshot publishes its own value for a
    | threshold that value wins, so the shell monitoring and this dashboard can
    | never disagree about what counts as a breach. A snapshot past its
    | staleness limit publishes nothing: its thresholds are as old as its
    | readings, and a relaxed one must not outlive the host that reported it.
    |
    */

    'thresholds' => [
        'disk_warn_pct' => (float) env('VPS_DISK_WARN_PCT', 80),
        'disk_crit_pct' => (float) env('VPS_DISK_CRIT_PCT', 90),
        'mem_warn_pct' => (float) env('VPS_MEM_WARN_PCT', 85),
        'mem_crit_pct' => (float) env('VPS_MEM_CRIT_PCT', 95),
        'mem_target_pct' => (float) env('VPS_MEM_TARGET_PCT', 90),
        'swap_warn_pct' => (float) env('VPS_SWAP_WARN_PCT', 5),
        'cert_warn_days' => (int) env('VPS_CERT_WARN_DAYS', 21),
        'cert_crit_days' => (int) env('VPS_CERT_CRIT_DAYS', 7),
        'load_per_cpu_warn' => (float) env('VPS_LOAD_PER_CPU_WARN', 2.0),
        'trend_warn_days' => (int) env('VPS_TREND_WARN_DAYS', 30),
        'docker_warn_bytes' => (int) env('VPS_DOCKER_WARN_BYTES', 15 * 1024 * 1024 * 1024),
    ],

];
