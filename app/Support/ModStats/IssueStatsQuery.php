<?php

declare(strict_types=1);

namespace App\Support\ModStats;

use App\Models\Mod;
use App\Models\ModIssue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Live issue tracker counts for one mod: issues opened and closed per UTC day, how long the closed ones took, and how
 * the currently open ones split by type and status. Read straight from mod_issues, which is indexed by
 * (mod_id, status, last_activity_at); soft-deleted issues are excluded by the model's own scope.
 */
final class IssueStatsQuery
{
    /**
     * @return array{opened: array<string, int>, closed: array<string, int>, close_seconds: list<int>, open_now: int, open_by_type: array<string, int>, open_by_status: array<string, int>}
     */
    public function forMod(Mod $mod, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $between = [$from->utc()->startOfDay(), $to->utc()->endOfDay()];

        $opened = ModIssue::query()
            ->where('mod_id', $mod->id)
            ->whereBetween('created_at', $between)
            ->pluck('created_at');

        // An issue closed and later reopened loses its closed_at, so it counts as open again rather than as closed here.
        $closed = ModIssue::query()
            ->where('mod_id', $mod->id)
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', $between)
            ->get(['created_at', 'closed_at']);

        $open = ModIssue::query()
            ->where('mod_id', $mod->id)
            ->open()
            ->get(['type', 'status']);

        $openByType = [];
        $openByStatus = [];

        foreach ($open as $issue) {
            $openByType[$issue->type->value] = ($openByType[$issue->type->value] ?? 0) + 1;
            $openByStatus[$issue->status->value] = ($openByStatus[$issue->status->value] ?? 0) + 1;
        }

        return [
            'opened' => $this->perDay($opened),
            'closed' => $this->perDay($closed->pluck('closed_at')),
            'close_seconds' => array_values($closed
                ->map(fn (ModIssue $issue): int => $issue->closed_at instanceof CarbonInterface
                    ? (int) abs($issue->created_at->diffInSeconds($issue->closed_at))
                    : 0)
                ->all()),
            'open_now' => $open->count(),
            'open_by_type' => $openByType,
            'open_by_status' => $openByStatus,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $moments
     * @return array<string, int>
     */
    private function perDay(Collection $moments): array
    {
        $counts = [];

        foreach ($moments as $moment) {
            if (! $moment instanceof CarbonInterface) {
                continue;
            }

            $day = $moment->utc()->toDateString();
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }
}
