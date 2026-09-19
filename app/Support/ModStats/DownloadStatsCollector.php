<?php

declare(strict_types=1);

namespace App\Support\ModStats;

use App\Enums\TrackingEventType;
use App\Models\ModVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Counts one UTC day of mod downloads from tracking_events, per version and country. The query filters a single day on
 * created_at and groups by plain columns, so it needs no SQL date functions and runs unchanged on every driver.
 */
final class DownloadStatsCollector
{
    public const string UNKNOWN_COUNTRY = 'XX';

    /**
     * @return list<array{mod_version_id: int, mod_id: int, country_code: string, downloads: int}>
     */
    public function forDay(CarbonImmutable $day): array
    {
        $start = $day->utc()->startOfDay();

        $rows = DB::table('tracking_events')
            ->join('mod_versions', 'mod_versions.id', '=', 'tracking_events.visitable_id')
            ->where('tracking_events.event_name', TrackingEventType::MOD_DOWNLOAD->value)
            ->where('tracking_events.visitable_type', (new ModVersion)->getMorphClass())
            ->where('tracking_events.created_at', '>=', $start)
            ->where('tracking_events.created_at', '<', $start->addDay())
            ->groupBy('tracking_events.visitable_id', 'mod_versions.mod_id', 'tracking_events.country_code')
            ->select(['tracking_events.visitable_id as mod_version_id', 'mod_versions.mod_id', 'tracking_events.country_code'])
            ->selectRaw('COUNT(*) as downloads')
            ->get();

        // Blank and lowercase codes normalise onto the same key as their clean forms, so rows are merged here. pgsql pads a
        // blank char(2) to two spaces, hence the trim.
        $counts = [];

        foreach ($rows as $row) {
            $code = is_string($row->country_code) ? mb_strtoupper(mb_trim($row->country_code)) : '';
            $country = preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : self::UNKNOWN_COUNTRY;
            $versionId = self::int($row->mod_version_id);
            $key = $versionId.'|'.$country;

            $counts[$key] ??= [
                'mod_version_id' => $versionId,
                'mod_id' => self::int($row->mod_id),
                'country_code' => $country,
                'downloads' => 0,
            ];
            $counts[$key]['downloads'] += self::int($row->downloads);
        }

        return array_values($counts);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
