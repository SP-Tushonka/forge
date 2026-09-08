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
 * Move a mod to a different owner. Every download, endorsement and comment badge on the mod
 * follows it, so this is admin-only and rate limited alongside the destructive account actions.
 */
final class TransferModOwnership
{
    public function execute(User $staff, Mod $mod, User $newOwner, bool $keepPreviousAsAuthor, string $reason): void
    {
        Gate::forUser($staff)->authorize('manageOwnership', $mod);

        $mod->loadMissing(['owner', 'additionalAuthors']);

        $this->guard($mod, $newOwner);

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        $previousOwner = $mod->owner;

        DB::transaction(function () use ($mod, $newOwner, $previousOwner, $keepPreviousAsAuthor): void {
            $mod->owner_id = $newOwner->id;
            $mod->save();

            // A user is either the owner or an additional author, never both.
            $mod->additionalAuthors()->detach($newOwner->id);

            if ($keepPreviousAsAuthor && $previousOwner instanceof User) {
                $mod->additionalAuthors()->syncWithoutDetaching([$previousOwner->id]);
            }
        });

        Track::eventSync(
            TrackingEventType::MOD_OWNERSHIP_TRANSFER,
            $mod,
            ['previous_owner_id' => $previousOwner?->id, 'new_owner_id' => $newOwner->id],
            isModerationAction: true,
            reason: $reason,
        );

        $previousOwner?->notify(new ModOwnershipChangedNotification(
            ModOwnershipChange::Transferred,
            $mod->name,
            $mod->detail_url,
            isNewOwner: false,
            reason: $reason,
        ));

        $newOwner->notify(new ModOwnershipChangedNotification(
            ModOwnershipChange::Transferred,
            $mod->name,
            $mod->detail_url,
            isNewOwner: true,
            reason: $reason,
        ));
    }

    /**
     * Refusals, in the order a staff member is most likely to hit them.
     */
    private function guard(Mod $mod, User $newOwner): void
    {
        throw_if(
            $newOwner->id === $mod->owner_id,
            StaffActionException::because('That user already owns this mod.')
        );

        throw_if(
            $newOwner->isBanned(),
            StaffActionException::because('Cannot transfer ownership to a banned account.')
        );

        // A locked account keeps an undeliverable "@unclaimed.invalid" address with a null
        // email_verified_at (DetachUserEmail), so it can never verify again. ModPolicy::update
        // also requires a verified email, so handing a mod to such an account orphans it.
        throw_if(
            ! $newOwner->hasVerifiedEmail(),
            StaffActionException::because('Cannot transfer ownership to an account with an unverified email address.')
        );

        foreach ($mod->additionalAuthors as $author) {
            if ($newOwner->hasBlocked($author->id) || $newOwner->isBlockedBy($author->id)) {
                throw StaffActionException::because(
                    $author->name.' has a block relationship with an author of this mod.'
                );
            }
        }
    }
}
