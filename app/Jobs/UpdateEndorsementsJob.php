<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Mod;
use App\Models\Scopes\PublishedScope;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Timeout(60)]
#[Backoff([1, 5, 10])]
#[Tries(3)]
final class UpdateEndorsementsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Repair drift in the denormalised endorsement counts.
     */
    public function handle(): void
    {
        /** @var array<int, int> $endorsementCounts */
        $endorsementCounts = DB::table('mod_endorsements')
            ->whereNull('revoked_at')
            ->groupBy('mod_id')
            ->selectRaw('mod_id, COUNT(*) AS endorsements')
            ->pluck('endorsements', 'mod_id')
            ->all();

        Mod::query()
            // Nothing is authenticated inside a queued job, so PublishedScope would otherwise hide every
            // unpublished and future dated mod from repair and let their counters drift forever.
            ->withoutGlobalScope(PublishedScope::class)
            ->select(['id', 'endorsements_count'])
            ->chunkById(1000, function (Collection $mods) use ($endorsementCounts): void {
                $modIdsByCount = [];

                /** @var Mod $mod */
                foreach ($mods as $mod) {
                    $count = $endorsementCounts[$mod->id] ?? 0;
                    if ($count !== $mod->endorsements_count) {
                        $modIdsByCount[$count][] = $mod->id;
                    }
                }

                foreach ($modIdsByCount as $count => $modIds) {
                    DB::table('mods')
                        ->whereIn('id', $modIds)
                        ->update(['endorsements_count' => $count]);
                }
            });
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('UpdateEndorsementsJob failed', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
