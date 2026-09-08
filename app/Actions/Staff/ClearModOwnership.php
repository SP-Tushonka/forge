<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\ModOwnershipChange;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Facades\Track;
use App\Models\Mod;
use App\Models\User;
use App\Notifications\ModOwnershipChangedNotification;
use App\Support\StaffActionLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Remove a mod's owner, returning it to the claimable pool. ModClaim only operates on mods with
 * a null owner, so this is the one way an owned mod becomes claimable again.
 */
final class ClearModOwnership
{
    public function execute(User $staff, Mod $mod, bool $keepPreviousAsAuthor, string $reason): void
    {
        Gate::forUser($staff)->authorize('manageOwnership', $mod);

        throw_if(
            $mod->owner_id === null,
            StaffActionException::because('This mod has no owner to clear.')
        );

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        $mod->loadMissing('owner');

        $previousOwner = $mod->owner;

        DB::transaction(function () use ($mod, $previousOwner, $keepPreviousAsAuthor): void {
            $mod->owner_id = null;
            $mod->save();

            if ($keepPreviousAsAuthor && $previousOwner instanceof User) {
                $mod->additionalAuthors()->syncWithoutDetaching([$previousOwner->id]);
            }
        });

        Track::eventSync(
            TrackingEventType::MOD_OWNERSHIP_CLEARED,
            $mod,
            ['previous_owner_id' => $previousOwner?->id],
            isModerationAction: true,
            reason: $reason,
        );

        $previousOwner?->notify(new ModOwnershipChangedNotification(
            ModOwnershipChange::Cleared,
            $mod->name,
            $mod->detail_url,
            isNewOwner: false,
            reason: $reason,
        ));
    }
}
