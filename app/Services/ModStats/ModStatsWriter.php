<?php

declare(strict_types=1);

namespace App\Services\ModStats;

use App\Enums\ModStatsSource;
use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersionDailyDownload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles one UTC day of stored stats with fresh counts from a source. Only rows whose count changed are written, so
 * the 15-minute sync doesn't rewrite (and binlog) thousands of unchanged rows. Rows the source stopped reporting are
 * removed, and each source writes only its own mod_daily_stats column.
 */
final class ModStatsWriter
{
    /**
     * Rows per upsert/delete statement, well under the 65k bound-parameter limit of MySQL and pgsql.
     */
    private const int CHUNK = 1000;

    /**
     * @param  list<array{mod_version_id: int, mod_id: int, country_code: string, downloads: int}>  $rows
     */
    public function writeDownloads(CarbonImmutable $day, array $rows): bool
    {
        $versions = [];
        $countries = [];
        $mods = [];

        foreach ($rows as $row) {
            $versions[$row['mod_version_id']] ??= ['mod_id' => $row['mod_id'], 'downloads' => 0];
            $versions[$row['mod_version_id']]['downloads'] += $row['downloads'];

            $countryKey = $row['mod_id'].'|'.$row['country_code'];
            $countries[$countryKey] ??= ['mod_id' => $row['mod_id'], 'country_code' => $row['country_code'], 'downloads' => 0];
            $countries[$countryKey]['downloads'] += $row['downloads'];

            $mods[$row['mod_id']] = ($mods[$row['mod_id']] ?? 0) + $row['downloads'];
        }

        $date = $day->toDateString();

        return DB::transaction(function () use ($date, $versions, $countries, $mods): bool {
            $versionsChanged = $this->syncVersions($date, $versions);
            $countriesChanged = $this->syncCountries($date, $countries);
            $modsChanged = $this->syncModColumn($date, ModStatsSource::Downloads, $mods);

            return $versionsChanged || $countriesChanged || $modsChanged;
        });
    }

    /**
     * @param  array<int, int>  $views  Views per mod ID; pass it through knownModsOnly() first.
     */
    public function writeViews(CarbonImmutable $day, array $views): bool
    {
        $date = $day->toDateString();

        return DB::transaction(fn (): bool => $this->syncModColumn($date, ModStatsSource::Views, $views));
    }

    /**
     * Drop mod IDs that no longer exist, so the foreign key never rejects a write.
     *
     * @param  array<int, int>  $counts
     * @return array<int, int>
     */
    public function knownModsOnly(array $counts): array
    {
        $known = [];

        foreach (array_chunk(array_keys($counts), self::CHUNK) as $ids) {
            foreach (Mod::query()->withoutGlobalScopes()->whereIn('id', $ids)->get(['id']) as $mod) {
                $known[$mod->id] = true;
            }
        }

        return array_intersect_key($counts, $known);
    }

    public function storedTotal(CarbonImmutable $day, ModStatsSource $source): int
    {
        return (int) ModDailyStat::query()->where('date', $day->toDateString())->sum($source->value);
    }

    /**
     * @param  array<int, array{mod_id: int, downloads: int}>  $fresh  Keyed by mod version ID.
     */
    private function syncVersions(string $date, array $fresh): bool
    {
        $stored = ModVersionDailyDownload::query()
            ->where('date', $date)
            ->get(['id', 'mod_version_id', 'downloads'])
            ->keyBy('mod_version_id');

        $upserts = [];

        foreach ($fresh as $versionId => $counts) {
            if ($stored->get($versionId)?->downloads !== $counts['downloads']) {
                $upserts[] = ['mod_version_id' => $versionId, 'mod_id' => $counts['mod_id'], 'date' => $date, 'downloads' => $counts['downloads']];
            }
        }

        // Not except(): on an Eloquent collection it filters by primary key, not by the array key.
        $staleIds = $stored
            ->reject(fn (ModVersionDailyDownload $row): bool => isset($fresh[$row->mod_version_id]))
            ->pluck('id')
            ->all();

        foreach (array_chunk($upserts, self::CHUNK) as $chunk) {
            ModVersionDailyDownload::query()->upsert($chunk, ['mod_version_id', 'date'], ['downloads']);
        }

        foreach (array_chunk($staleIds, self::CHUNK) as $chunk) {
            ModVersionDailyDownload::query()->whereKey($chunk)->delete();
        }

        return $upserts !== [] || $staleIds !== [];
    }

    /**
     * @param  array<string, array{mod_id: int, country_code: string, downloads: int}>  $fresh  Keyed by "modId|CC".
     */
    private function syncCountries(string $date, array $fresh): bool
    {
        $stored = ModCountryDailyDownload::query()
            ->where('date', $date)
            ->get(['id', 'mod_id', 'country_code', 'downloads'])
            ->keyBy(fn (ModCountryDailyDownload $row): string => $row->mod_id.'|'.$row->country_code);

        $upserts = [];

        foreach ($fresh as $key => $counts) {
            if ($stored->get($key)?->downloads !== $counts['downloads']) {
                $upserts[] = [...$counts, 'date' => $date];
            }
        }

        $staleIds = $stored
            ->reject(fn (ModCountryDailyDownload $row): bool => isset($fresh[$row->mod_id.'|'.$row->country_code]))
            ->pluck('id')
            ->all();

        foreach (array_chunk($upserts, self::CHUNK) as $chunk) {
            ModCountryDailyDownload::query()->upsert($chunk, ['mod_id', 'date', 'country_code'], ['downloads']);
        }

        foreach (array_chunk($staleIds, self::CHUNK) as $chunk) {
            ModCountryDailyDownload::query()->whereKey($chunk)->delete();
        }

        return $upserts !== [] || $staleIds !== [];
    }

    /**
     * @param  array<int, int>  $fresh  Count per mod ID.
     */
    private function syncModColumn(string $date, ModStatsSource $source, array $fresh): bool
    {
        $column = $source->value;
        $stored = ModDailyStat::query()
            ->where('date', $date)
            ->get(['id', 'mod_id', 'downloads', 'views'])
            ->keyBy('mod_id');

        $upserts = [];

        foreach ($fresh as $modId => $count) {
            $row = $stored->get($modId);

            if (! $row instanceof ModDailyStat || $this->countOf($row, $source) !== $count) {
                $upserts[] = ['mod_id' => $modId, 'date' => $date, $column => $count];
            }
        }

        $staleIds = $stored
            ->filter(fn (ModDailyStat $row): bool => ! isset($fresh[$row->mod_id]) && $this->countOf($row, $source) > 0)
            ->pluck('id')
            ->all();

        foreach (array_chunk($upserts, self::CHUNK) as $chunk) {
            ModDailyStat::query()->upsert($chunk, ['mod_id', 'date'], [$column]);
        }

        foreach (array_chunk($staleIds, self::CHUNK) as $chunk) {
            ModDailyStat::query()->whereKey($chunk)->update([$column => 0]);
        }

        if ($staleIds !== []) {
            ModDailyStat::query()->where('date', $date)->where('downloads', 0)->where('views', 0)->delete();
        }

        return $upserts !== [] || $staleIds !== [];
    }

    private function countOf(ModDailyStat $row, ModStatsSource $source): int
    {
        return match ($source) {
            ModStatsSource::Downloads => $row->downloads,
            ModStatsSource::Views => $row->views,
        };
    }
}
