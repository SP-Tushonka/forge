<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffActionType;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Facades\Track;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use App\Support\StaffActionLimiter;
use App\Support\UndeliverableAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes the Discord connection. This is a support action, NOT a revocation:
 * the user can re-link by signing in with Discord again, because the OAuth
 * callback re-attaches on a matching email. Use LockAccount for incident response.
 */
final class UnlinkDiscordConnection
{
    public function execute(User $staff, User $target, string $reason): void
    {
        Gate::forUser($staff)->authorize('unlinkDiscord', $target);

        $target->loadMissing('oAuthConnections');

        if ($target->oAuthConnections->where('provider', 'discord')->isEmpty()) {
            throw StaffActionException::because('This account has no Discord connection.');
        }

        if ($target->password === null && UndeliverableAddress::check($target->email)) {
            throw StaffActionException::because('This would permanently lock the account out: it has no password and no reachable email address. Use Lock Account if that is the intent.');
        }

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        DB::transaction(function () use ($target): void {
            $target->oAuthConnections()->where('provider', 'discord')->delete();
        });

        Track::eventSync(
            TrackingEventType::USER_DISCORD_UNLINK,
            $target,
            isModerationAction: true,
            reason: $reason,
        );

        $target->notify(new StaffAccountActionNotification(StaffActionType::DiscordUnlinked, $reason));
    }
}
