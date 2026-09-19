<?php

declare(strict_types=1);

namespace App\Support\ModStats;

use App\Enums\ModStatsSource;
use App\Models\ModDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The small pieces of mod stats pipeline state kept in the cache: a version number that page caches key on (increased
 * whenever the sync writes), when each source last synced (read by app:mod-stats-status), and the first day each source
 * has data for (so charts can say when collection started).
 */
final class ModStatsState
{
    private const string VERSION_KEY = 'mod-stats:version';

    public function version(): int
    {
        $version = Cache::get(self::VERSION_KEY, 0);

        // Redis returns incremented counters as numeric strings.
        return is_numeric($version) ? (int) $version : 0;
    }

    public function bumpVersion(): void
    {
        Cache::add(self::VERSION_KEY, 0);
        Cache::increment(self::VERSION_KEY);
    }

    public function markSynced(ModStatsSource $source): void
    {
        Cache::forever($this->lastSyncKey($source), CarbonImmutable::now('UTC')->getTimestamp());
    }

    public function lastSynced(ModStatsSource $source): ?CarbonImmutable
    {
        $timestamp = Cache::get($this->lastSyncKey($source));

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestampUTC((int) $timestamp) : null;
    }

    /**
     * The first UTC day with a non-zero count for the source, or null when there is none yet. Keyed on the stats version
     * so a backfill or prune is reflected on the next sync cycle.
     */
    public function dataSince(ModStatsSource $source): ?CarbonImmutable
    {
        $date = Cache::remember(
            sprintf('mod-stats:%d:since:%s', $this->version(), $source->value),
            3600,
            fn (): mixed => ModDailyStat::query()->where($source->value, '>', 0)->min('date'),
        );

        return is_string($date) ? CarbonImmutable::parse($date, 'UTC')->startOfDay() : null;
    }

    private function lastSyncKey(ModStatsSource $source): string
    {
        return 'mod-stats:last-sync:'.$source->value;
    }
}
