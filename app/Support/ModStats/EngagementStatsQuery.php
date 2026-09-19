<?php

declare(strict_types=1);

namespace App\Support\ModStats;

use App\Enums\SpamStatus;
use App\Models\Mod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Live engagement counts per UTC day for a set of mods, counting only items that still exist: reactions on the mod
 * itself, comments (replies included; spam and deleted excluded), active endorsements by the day they were given, and
 * list saves that were not tombstoned. Timestamps are grouped in PHP so no driver-specific date SQL is needed.
 */
final class EngagementStatsQuery
{
    /**
     * @var list<string>
     */
    public const array METRICS = ['reactions', 'comments', 'endorsements', 'list_saves'];

    /**
     * @param  list<int>  $modIds
     * @return array<string, array<string, int>>
     */
    public function dailyCounts(array $modIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($modIds === []) {
            return array_fill_keys(self::METRICS, []);
        }

        $morph = (new Mod)->getMorphClass();
        $between = [$from->utc()->startOfDay(), $to->utc()->endOfDay()];

        return [
            'reactions' => $this->perDay(DB::table('reactions')
                ->where('reactable_type', $morph)
                ->whereIn('reactable_id', $modIds)
                ->whereBetween('created_at', $between)
                ->pluck('created_at')),
            'comments' => $this->perDay(DB::table('comments')
                ->where('commentable_type', $morph)
                ->whereIn('commentable_id', $modIds)
                ->whereNull('deleted_at')
                ->where('spam_status', '!=', SpamStatus::SPAM->value)
                ->whereBetween('created_at', $between)
                ->pluck('created_at')),
            'endorsements' => $this->perDay(DB::table('mod_endorsements')
                ->whereIn('mod_id', $modIds)
                ->whereNull('revoked_at')
                ->whereBetween('endorsed_at', $between)
                ->pluck('endorsed_at')),
            'list_saves' => $this->perDay(DB::table('mod_list_items')
                ->where('listable_type', $morph)
                ->whereIn('listable_id', $modIds)
                ->whereNull('tombstoned_at')
                ->whereBetween('created_at', $between)
                ->pluck('created_at')),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $timestamps  UTC "Y-m-d H:i:s" strings.
     * @return array<string, int>
     */
    private function perDay(Collection $timestamps): array
    {
        $counts = [];

        foreach ($timestamps as $timestamp) {
            if (! is_string($timestamp)) {
                continue;
            }

            $day = mb_substr($timestamp, 0, 10);
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
