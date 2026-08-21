<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

final readonly class BanObserver
{
    /**
     * Handle the Ban "created" event.
     */
    public function created(Ban $ban): void
    {
        $this->forgetBanState($ban);
    }

    /**
     * Handle the Ban "updated" event.
     */
    public function updated(Ban $ban): void
    {
        $this->forgetBanState($ban);
    }

    /**
     * Handle the Ban "deleted" event.
     */
    public function deleted(Ban $ban): void
    {
        $this->forgetBanState($ban);
    }

    /**
     * Handle the Ban "restored" event.
     */
    public function restored(Ban $ban): void
    {
        $this->forgetBanState($ban);
    }

    /**
     * Forget the cached ban state for the banned user and resync their search index entry.
     */
    private function forgetBanState(Ban $ban): void
    {
        if ($ban->bannable_type !== User::class || $ban->bannable_id === null) {
            return;
        }

        Cache::forget(User::banStateCacheKey($ban->bannable_id));

        $user = User::query()->find($ban->bannable_id);

        if (! $user instanceof User) {
            return;
        }

        // ban()/unban() only write the Ban row, so no User model event fires and Scout never re-evaluates
        // shouldBeSearchable(). load(), not loadMissing() — the relation may already hold the pre-change state.
        $user->load('bans');

        $user->shouldBeSearchable() ? $user->searchable() : $user->unsearchable();
    }
}
