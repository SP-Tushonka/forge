<?php

declare(strict_types=1);

namespace App\Services\ModStats;

use App\Enums\ModStatsSource;
use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersion;
use App\Models\ModVersionDailyDownload;
use App\Models\User;
use App\Support\DataTransferObjects\StatsRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Locale;

/**
 * Builds the mod stats dashboard payloads from the summary tables plus live engagement, shaped for flux:chart and plain
 * Blade. Payloads are cached against the stats version, which the sync increases whenever it writes, so a page refreshes
 * within one sync cycle without running its queries twice in between.
 *
 * @phpstan-type Change array{value: int|float, absolute: bool}
 * @phpstan-type SummaryTile array{key: string, label: string, total: int, change: Change|null}
 * @phpstan-type TrafficPoint array{date: string, label: string, downloads: int|null, views: int|null, partial: bool, release: string|null}
 * @phpstan-type EngagementPoint array{date: string, label: string, reactions: int, comments: int, endorsements: int, list_saves: int}
 * @phpstan-type Versions array{labels: array<string, string>, rows: list<array<string, int|string>>}
 * @phpstan-type Country array{code: string, label: string, downloads: int, share: float}
 * @phpstan-type Downstream array{count: int, top: list<array{name: string, url: string, downloads: int}>}
 * @phpstan-type Since array{downloads: string|null, views: string|null}
 * @phpstan-type ModReport array{summary: list<SummaryTile>, traffic: list<TrafficPoint>, versions: Versions, countries: list<Country>, engagement: list<EngagementPoint>, downstream: Downstream, since: Since}
 * @phpstan-type OverviewRow array{name: string, url: string, status: string|null, downloads: int, downloads_change: Change|null, views: int, views_change: Change|null, trend: list<array{date: string, downloads: int|null}>}
 * @phpstan-type OverviewReport array{summary: list<SummaryTile>, traffic: list<TrafficPoint>, mods: list<OverviewRow>, since: Since}
 */
final readonly class ModStatsService
{
    public const int COUNTRY_MIN_DOWNLOADS = 10;

    private const int CACHE_TTL_SECONDS = 3600;

    private const int TOP_VERSIONS = 5;

    private const int TOP_COUNTRIES = 10;

    private const int TOP_DOWNSTREAM = 10;

    /**
     * Below this previous-period count a percentage is noise ("+300%"), so the change is shown as a plain difference.
     */
    private const int ABSOLUTE_CHANGE_BELOW = 10;

    /**
     * @var array<string, string>
     */
    private const array ENGAGEMENT_LABELS = [
        'reactions' => 'Reactions',
        'comments' => 'Comments',
        'endorsements' => 'Endorsements',
        'list_saves' => 'List saves',
    ];

    public function __construct(
        private ModStatsState $state,
        private EngagementStatsQuery $engagement,
        private DownstreamModsQuery $downstream,
    ) {}

    /**
     * @return ModReport
     */
    public function forMod(Mod $mod, StatsRange $range): array
    {
        /** @var ModReport */
        return Cache::remember(
            $this->cacheKey('mod', $mod->id, $range),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildModReport($mod, $range),
        );
    }

    /**
     * @return OverviewReport
     */
    public function forUser(User $user, StatsRange $range): array
    {
        /** @var OverviewReport */
        return Cache::remember(
            $this->cacheKey('user', $user->id, $range),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildOverview($user, $range),
        );
    }

    private function cacheKey(string $subject, int $id, StatsRange $range): string
    {
        return sprintf('mod-stats:%d:%s:%d:%s', $this->state->version(), $subject, $id, $range->cacheKey());
    }

    /**
     * @return ModReport
     */
    private function buildModReport(Mod $mod, StatsRange $range): array
    {
        $since = $this->since();
        [$previousFrom] = $range->previousWindow();
        $daily = $this->dailyTotals([$mod->id], $previousFrom, $range->end())[$mod->id] ?? [];
        $engagement = $this->engagement->dailyCounts([$mod->id], $previousFrom, $range->end());

        return [
            'summary' => $this->summary($range, $daily, $engagement, $since),
            'traffic' => $this->traffic($range, $daily, $since, $this->releases($mod, $range)),
            'versions' => $this->versions($mod, $range),
            'countries' => $this->countries($mod, $range),
            'engagement' => $this->engagementSeries($range, $engagement),
            'downstream' => $this->downstreamFor($mod, $range),
            'since' => $this->sinceStrings($since),
        ];
    }

    /**
     * @return OverviewReport
     */
    private function buildOverview(User $user, StatsRange $range): array
    {
        $mods = Mod::query()
            ->withoutGlobalScopes()
            ->where(fn (Builder $query): Builder => $query
                ->where('owner_id', $user->id)
                ->orWhereHas('additionalAuthors', fn (Builder $authors): Builder => $authors->whereKey($user->id)))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'disabled', 'published_at']);

        $since = $this->since();
        [$previousFrom] = $range->previousWindow();
        $modIds = array_values($mods->map(fn (Mod $mod): int => $mod->id)->all());
        $perMod = $this->dailyTotals($modIds, $previousFrom, $range->end());

        $combined = [];

        foreach ($perMod as $days) {
            foreach ($days as $date => $counts) {
                $combined[$date] ??= ['downloads' => 0, 'views' => 0];
                $combined[$date]['downloads'] += $counts['downloads'];
                $combined[$date]['views'] += $counts['views'];
            }
        }

        $rows = [];

        foreach ($mods as $mod) {
            $daily = $perMod[$mod->id] ?? [];
            $downloads = $this->tile('downloads', 'Downloads', $this->column($daily, 'downloads'), $range, $this->comparable($since['downloads'], $range));
            $views = $this->tile('views', 'Views', $this->column($daily, 'views'), $range, $this->comparable($since['views'], $range));

            $rows[] = [
                'name' => $mod->name,
                'url' => route('mod.stats', ['modId' => $mod->id, 'slug' => $mod->slug]),
                'status' => $this->status($mod),
                'downloads' => $downloads['total'],
                'downloads_change' => $downloads['change'],
                'views' => $views['total'],
                'views_change' => $views['change'],
                'trend' => array_map(
                    fn (array $point): array => ['date' => $point['date'], 'downloads' => $point['downloads']],
                    $this->traffic($range, $daily, $since),
                ),
            ];
        }

        return [
            'summary' => $this->summary($range, $combined, $this->engagement->dailyCounts($modIds, $previousFrom, $range->end()), $since),
            'traffic' => $this->traffic($range, $combined, $since),
            'mods' => $rows,
            'since' => $this->sinceStrings($since),
        ];
    }

    /**
     * @return array{downloads: CarbonImmutable|null, views: CarbonImmutable|null}
     */
    private function since(): array
    {
        return [
            'downloads' => $this->state->dataSince(ModStatsSource::Downloads),
            'views' => $this->state->dataSince(ModStatsSource::Views),
        ];
    }

    /**
     * @param  array{downloads: CarbonImmutable|null, views: CarbonImmutable|null}  $since
     * @return Since
     */
    private function sinceStrings(array $since): array
    {
        return [
            'downloads' => $since['downloads']?->toDateString(),
            'views' => $since['views']?->toDateString(),
        ];
    }

    /**
     * A change figure is only fair when the source already had data at the start of the previous window.
     */
    private function comparable(?CarbonImmutable $since, StatsRange $range): bool
    {
        return $since instanceof CarbonImmutable && $since->lte($range->previousWindow()[0]);
    }

    /**
     * @param  list<int>  $modIds
     * @return array<int, array<string, array{downloads: int, views: int}>> Mod ID => Y-m-d => counts.
     */
    private function dailyTotals(array $modIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($modIds === []) {
            return [];
        }

        $totals = [];
        $rows = ModDailyStat::query()
            ->whereIn('mod_id', $modIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['mod_id', 'date', 'downloads', 'views']);

        foreach ($rows as $row) {
            $totals[$row->mod_id][mb_substr($row->date, 0, 10)] = ['downloads' => $row->downloads, 'views' => $row->views];
        }

        return $totals;
    }

    /**
     * @param  array<string, array{downloads: int, views: int}>  $daily
     * @param  'downloads'|'views'  $field
     * @return array<string, int>
     */
    private function column(array $daily, string $field): array
    {
        return array_map(fn (array $counts): int => $counts[$field], $daily);
    }

    /**
     * @param  array<string, array{downloads: int, views: int}>  $daily
     * @param  array<string, array<string, int>>  $engagement
     * @param  array{downloads: CarbonImmutable|null, views: CarbonImmutable|null}  $since
     * @return list<SummaryTile>
     */
    private function summary(StatsRange $range, array $daily, array $engagement, array $since): array
    {
        $tiles = [
            $this->tile('downloads', 'Downloads', $this->column($daily, 'downloads'), $range, $this->comparable($since['downloads'], $range)),
            $this->tile('views', 'Views', $this->column($daily, 'views'), $range, $this->comparable($since['views'], $range)),
        ];

        foreach (self::ENGAGEMENT_LABELS as $metric => $label) {
            $tiles[] = $this->tile($metric, $label, $engagement[$metric] ?? [], $range, true);
        }

        return $tiles;
    }

    /**
     * @param  array<string, int>  $perDay
     * @return SummaryTile
     */
    private function tile(string $key, string $label, array $perDay, StatsRange $range, bool $comparable): array
    {
        [$currentFrom, $currentTo] = $range->currentWindow();
        [$previousFrom, $previousTo] = $range->previousWindow();

        return [
            'key' => $key,
            'label' => $label,
            'total' => $this->sumBetween($perDay, $range->start(), $range->end()),
            'change' => $comparable
                ? $this->change($this->sumBetween($perDay, $currentFrom, $currentTo), $this->sumBetween($perDay, $previousFrom, $previousTo))
                : null,
        ];
    }

    /**
     * @param  array<string, int>  $perDay
     */
    private function sumBetween(array $perDay, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $sum = 0;

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $sum += $perDay[$day->toDateString()] ?? 0;
        }

        return $sum;
    }

    /**
     * @return Change
     */
    private function change(int $current, int $previous): array
    {
        if ($previous < self::ABSOLUTE_CHANGE_BELOW) {
            return ['value' => $current - $previous, 'absolute' => true];
        }

        return ['value' => round(($current - $previous) / $previous * 100, 1), 'absolute' => false];
    }

    /**
     * @param  array<string, array{downloads: int, views: int}>  $daily
     * @param  array{downloads: CarbonImmutable|null, views: CarbonImmutable|null}  $since
     * @param  array<string, string>  $releases  Bucket key => versions released in it.
     * @return list<TrafficPoint>
     */
    private function traffic(StatsRange $range, array $daily, array $since, array $releases = []): array
    {
        $downloads = $this->column($daily, 'downloads');
        $views = $this->column($daily, 'views');
        $points = [];

        foreach ($range->buckets() as $bucket) {
            $points[] = [
                'date' => $bucket['key'],
                'label' => $bucket['label'],
                'downloads' => $this->hasData($bucket['end'], $since['downloads']) ? $this->sumBetween($downloads, $bucket['start'], $bucket['end']) : null,
                'views' => $this->hasData($bucket['end'], $since['views']) ? $this->sumBetween($views, $bucket['start'], $bucket['end']) : null,
                'partial' => $bucket['partial'],
                'release' => $releases[$bucket['key']] ?? null,
            ];
        }

        return $points;
    }

    private function hasData(CarbonImmutable $bucketEnd, ?CarbonImmutable $since): bool
    {
        return $since instanceof CarbonImmutable && $bucketEnd->gte($since);
    }

    /**
     * @return array<string, string>
     */
    private function releases(Mod $mod, StatsRange $range): array
    {
        $releases = [];
        $versions = ModVersion::query()
            ->withoutGlobalScopes()
            ->where('mod_id', $mod->id)
            ->where('disabled', false)
            ->whereBetween('published_at', [$range->start(), $range->end()->endOfDay()])
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->get(['version', 'published_at']);

        foreach ($versions as $version) {
            if ($version->published_at === null) {
                continue;
            }

            $key = $range->bucketKey($version->published_at);
            $releases[$key] = isset($releases[$key]) ? $releases[$key].', '.$version->version : $version->version;
        }

        return $releases;
    }

    /**
     * The newest versions with downloads in the range, by semver, each with its own series; everything older is folded
     * into one. Picking by recency (not download rank) keeps a version's colour stable when the range changes.
     *
     * @return Versions
     */
    private function versions(Mod $mod, StatsRange $range): array
    {
        $rows = ModVersionDailyDownload::query()
            ->where('mod_id', $mod->id)
            ->whereBetween('date', [$range->start()->toDateString(), $range->end()->toDateString()])
            ->get(['mod_version_id', 'date', 'downloads']);

        $versionIds = array_values(array_unique($rows->map(fn (ModVersionDailyDownload $row): int => $row->mod_version_id)->all()));

        $newest = ModVersion::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $versionIds)
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor')
            ->orderByDesc('version_patch')
            ->orderByRaw('CASE WHEN version_labels = ? THEN 0 ELSE 1 END', [''])
            ->orderBy('version_labels')
            ->limit(self::TOP_VERSIONS)
            ->get(['id', 'version']);

        $fields = [];
        $labels = [];

        foreach ($newest->values() as $position => $version) {
            $field = 'v'.($position + 1);
            $fields[$version->id] = $field;
            $labels[$field] = $version->version;
        }

        if (count($versionIds) > count($fields)) {
            $labels['older'] = 'Older versions';
        }

        $perBucket = [];

        foreach ($rows as $row) {
            $key = $range->bucketKey(CarbonImmutable::parse($row->date, 'UTC'));
            $field = $fields[$row->mod_version_id] ?? 'older';
            $perBucket[$key][$field] = ($perBucket[$key][$field] ?? 0) + $row->downloads;
        }

        $chartRows = [];

        foreach ($range->buckets() as $bucket) {
            $point = ['date' => $bucket['key'], 'label' => $bucket['label']];

            foreach (array_keys($labels) as $field) {
                $point[$field] = $perBucket[$bucket['key']][$field] ?? 0;
            }

            $chartRows[] = $point;
        }

        return ['labels' => $labels, 'rows' => $chartRows];
    }

    /**
     * Range totals per country. Countries under the threshold are only ever counted inside "Other", never named, so a
     * small mod's page can't point at a single person.
     *
     * @return list<Country>
     */
    private function countries(Mod $mod, StatsRange $range): array
    {
        $totals = [];
        $rows = ModCountryDailyDownload::query()
            ->where('mod_id', $mod->id)
            ->whereBetween('date', [$range->start()->toDateString(), $range->end()->toDateString()])
            ->get(['country_code', 'downloads']);

        foreach ($rows as $row) {
            $totals[$row->country_code] = ($totals[$row->country_code] ?? 0) + $row->downloads;
        }

        $grand = array_sum($totals);

        if ($grand === 0) {
            return [];
        }

        $unknown = $totals[DownloadStatsCollector::UNKNOWN_COUNTRY] ?? 0;
        unset($totals[DownloadStatsCollector::UNKNOWN_COUNTRY]);
        arsort($totals);

        $countries = [];
        $otherDownloads = 0;
        $otherCountries = 0;

        foreach ($totals as $code => $downloads) {
            if (count($countries) < self::TOP_COUNTRIES && $downloads >= self::COUNTRY_MIN_DOWNLOADS) {
                $countries[] = $this->country((string) $code, $this->countryName((string) $code), $downloads, $grand);

                continue;
            }

            $otherDownloads += $downloads;
            $otherCountries++;
        }

        if ($otherCountries > 0) {
            $label = sprintf('Other (%d %s)', $otherCountries, Str::plural('country', $otherCountries));
            $countries[] = $this->country('other', $label, $otherDownloads, $grand);
        }

        if ($unknown > 0) {
            $countries[] = $this->country('unknown', 'Unknown', $unknown, $grand);
        }

        return $countries;
    }

    /**
     * @return Country
     */
    private function country(string $code, string $label, int $downloads, int $grand): array
    {
        return ['code' => $code, 'label' => $label, 'downloads' => $downloads, 'share' => round($downloads / $grand * 100, 1)];
    }

    private function countryName(string $code): string
    {
        $name = Locale::getDisplayRegion('-'.$code, 'en');

        return is_string($name) && $name !== '' ? $name : $code;
    }

    /**
     * @param  array<string, array<string, int>>  $engagement
     * @return list<EngagementPoint>
     */
    private function engagementSeries(StatsRange $range, array $engagement): array
    {
        $points = [];

        foreach ($range->buckets() as $bucket) {
            $points[] = [
                'date' => $bucket['key'],
                'label' => $bucket['label'],
                'reactions' => $this->sumBetween($engagement['reactions'] ?? [], $bucket['start'], $bucket['end']),
                'comments' => $this->sumBetween($engagement['comments'] ?? [], $bucket['start'], $bucket['end']),
                'endorsements' => $this->sumBetween($engagement['endorsements'] ?? [], $bucket['start'], $bucket['end']),
                'list_saves' => $this->sumBetween($engagement['list_saves'] ?? [], $bucket['start'], $bucket['end']),
            ];
        }

        return $points;
    }

    /**
     * @return Downstream
     */
    private function downstreamFor(Mod $mod, StatsRange $range): array
    {
        $ids = $this->downstream->dependentModIds($mod);

        if ($ids === []) {
            return ['count' => 0, 'top' => []];
        }

        $downloads = [];

        foreach ($this->dailyTotals($ids, $range->start(), $range->end()) as $modId => $days) {
            $downloads[$modId] = array_sum(array_column($days, 'downloads'));
        }

        $top = Mod::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get(['id', 'name', 'slug'])
            ->map(fn (Mod $dependent): array => [
                'name' => $dependent->name,
                'url' => route('mod.show', ['modId' => $dependent->id, 'slug' => $dependent->slug]),
                'downloads' => $downloads[$dependent->id] ?? 0,
            ])
            ->sortByDesc('downloads')
            ->take(self::TOP_DOWNSTREAM)
            ->values()
            ->all();

        return ['count' => count($ids), 'top' => array_values($top)];
    }

    private function status(Mod $mod): ?string
    {
        if ($mod->disabled) {
            return 'Disabled';
        }

        if ($mod->published_at === null || $mod->published_at->isFuture()) {
            return 'Unpublished';
        }

        return null;
    }
}
