<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AltIndicatorType;
use App\Models\AltWatch;
use App\Models\AltWatchMatch;
use App\Models\User;
use App\Notifications\AltWatchMatchedNotification;
use App\Services\AltIndicatorService;
use App\Services\AltWatchMatchService;
use App\Support\DataTransferObjects\AltIndicatorSet;
use App\Support\DataTransferObjects\AltWatchDraft;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Checks the accounts active since the last run against every active alt watch, records new matches, and alerts every
 * admin once per watch. Where each source table was read up to is kept in alt_monitor_cursors.
 */
#[Timeout(120)]
#[Tries(1)]
#[UniqueFor(300)]
final class SweepAltWatchesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Timestamp cursors step back this far, so rows written while a run is in progress are read by the next one.
    private const int OVERLAP_SECONDS = 60;

    // A backlog on the id sources is worked off over several runs, so one run stays within its timeout.
    private const int MAX_IDS_PER_RUN = 20000;

    // The timestamp sources only fill gaps (tracked activity reaches the id sources), so after downtime they skip ahead.
    private const int MAX_TIMESTAMP_LOOKBACK_MINUTES = 60;

    public function __construct()
    {
        $this->onQueue(config()->string('alt-detection.queue', 'alt-detection'));
    }

    public function handle(AltIndicatorService $indicators, AltWatchMatchService $matcher): void
    {
        $startedAt = CarbonImmutable::now();
        $cursors = DB::table('alt_monitor_cursors')->pluck('position', 'source')->all();
        $watches = AltWatch::query()->active()->with(['indicators', 'watchedUser'])->get();

        [$accountIds, $positions] = $this->activeAccounts($cursors, $startedAt, $watches->isNotEmpty());

        if ($accountIds !== []) {
            $sets = $indicators->forUsers($accountIds, $this->typesWatched($watches));

            foreach ($watches as $watch) {
                $this->sweepWatch($watch, $sets, $matcher);
            }
        }

        // Saved last, so a run that fails part-way is read again by the next one; matching is idempotent.
        $this->saveCursors($positions);
    }

    /**
     * The accounts with activity since the cursors, and the positions to store. A missing cursor starts at the current
     * position, so the first run never reads a backlog.
     *
     * @param  array<array-key, mixed>  $cursors
     * @return array{0: list<int>, 1: array<string, string>}
     */
    private function activeAccounts(array $cursors, CarbonImmutable $startedAt, bool $collect): array
    {
        $ids = [];
        $positions = [];

        // Read by id, not by time: tracking events are written by a queue and can arrive with an older created_at.
        foreach (['tracking_events' => 'visitor_id', 'comments' => 'user_id'] as $table => $accountColumn) {
            $last = $this->int(DB::table($table)->max('id'));
            $from = is_numeric($cursors[$table] ?? null) ? (int) $cursors[$table] : $last;
            $to = $collect ? min($last, $from + self::MAX_IDS_PER_RUN) : $last;
            $positions[$table] = (string) $to;

            if (! $collect || $to <= $from) {
                continue;
            }

            $query = DB::table($table)->where('id', '>', $from)->where('id', '<=', $to)->whereNotNull($accountColumn);

            if ($table === 'tracking_events') {
                $query->where('visitor_type', User::class)->where(AltIndicatorService::ownActivity(...));
            }

            $ids = [...$ids, ...$query->distinct()->pluck($accountColumn)->all()];
        }

        $earliest = $startedAt->subMinutes(self::MAX_TIMESTAMP_LOOKBACK_MINUTES);

        foreach (['user_devices' => 'user_id', 'users' => 'id'] as $table => $accountColumn) {
            $from = is_string($cursors[$table] ?? null) ? CarbonImmutable::parse($cursors[$table])->max($earliest) : $startedAt;
            $positions[$table] = $startedAt->subSeconds(self::OVERLAP_SECONDS)->toIso8601String();

            if ($collect) {
                $ids = [...$ids, ...DB::table($table)->where('updated_at', '>=', $from)->pluck($accountColumn)->all()];
            }
        }

        $ids = array_values(array_unique(array_map($this->int(...), $ids)));

        // Matches reference users by foreign key, so ids of accounts deleted since their activity are dropped.
        $existing = $ids === [] ? [] : array_map($this->int(...), User::query()->whereIn('id', $ids)->pluck('id')->all());

        return [array_values($existing), $positions];
    }

    /**
     * @param  Collection<int, AltWatch>  $watches
     * @return list<AltIndicatorType>
     */
    private function typesWatched(Collection $watches): array
    {
        $types = [];

        foreach ($watches as $watch) {
            foreach ($watch->indicators as $indicator) {
                $types[$indicator->type->value] = $indicator->type;
            }
        }

        return array_values($types);
    }

    /**
     * @param  array<string, string>  $positions
     */
    private function saveCursors(array $positions): void
    {
        $now = CarbonImmutable::now();

        DB::table('alt_monitor_cursors')->upsert(
            array_map(static fn (string $source, string $position): array => ['source' => $source, 'position' => $position, 'updated_at' => $now], array_keys($positions), $positions),
            ['source'],
            ['position', 'updated_at'],
        );
    }

    /**
     * @param  array<int, AltIndicatorSet>  $sets
     */
    private function sweepWatch(AltWatch $watch, array $sets, AltWatchMatchService $matcher): void
    {
        $draft = AltWatchDraft::fromWatch($watch);
        $idsByKey = [];

        foreach ($watch->indicators as $indicator) {
            $idsByKey[$indicator->toIndicator()->key()] = $indicator->id;
        }

        $now = CarbonImmutable::now();
        $newMatches = [];

        foreach ($sets as $userId => $set) {
            if ($userId === $watch->watched_user_id) {
                continue;
            }

            $keys = $matcher->matchedKeys($draft, $set);

            if ($keys === null) {
                continue;
            }

            $ids = array_map(static fn (string $key): int => $idsByKey[$key], $keys);
            $existing = AltWatchMatch::query()->where('alt_watch_id', $watch->id)->where('user_id', $userId)->first();

            if ($existing instanceof AltWatchMatch) {
                $existing->update(['matched_indicator_ids' => $ids, 'last_matched_at' => $now]);

                continue;
            }

            $match = AltWatchMatch::query()->createOrFirst(
                ['alt_watch_id' => $watch->id, 'user_id' => $userId],
                ['matched_indicator_ids' => $ids, 'baseline' => false, 'first_matched_at' => $now, 'last_matched_at' => $now],
            );

            if ($match->wasRecentlyCreated) {
                $newMatches[] = $match;
            }
        }

        if ($newMatches !== []) {
            $this->alert($watch, $newMatches);
        }
    }

    /**
     * @param  list<AltWatchMatch>  $matches
     */
    private function alert(AltWatch $watch, array $matches): void
    {
        $users = User::query()->whereIn('id', array_map(static fn (AltWatchMatch $match): int => $match->user_id, $matches))->get()->keyBy('id');
        $accounts = [];
        $kinds = [];

        foreach ($matches as $match) {
            $match->setRelation('watch', $watch);
            $accounts[] = ['id' => $match->user_id, 'name' => $users->get($match->user_id)->name ?? ''];
            $kinds = [...$kinds, ...$match->matchedKinds()];
        }

        Notification::send(
            User::query()->admins()->get(),
            new AltWatchMatchedNotification($watch->id, $watch->watchedName(), $watch->reason, $accounts, array_values(array_unique($kinds))),
        );
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
