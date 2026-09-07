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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * Incident response for a hijacked account: severs every route back in.
 * Unlinking alone is insufficient, because the OAuth callback re-attaches on a
 * matching email; detaching the email alone leaves the OAuth path open.
 */
final class LockAccount
{
    public function __construct(private readonly DetachUserEmail $detach) {}

    public function execute(User $staff, User $target, bool $recoverable, string $reason): void
    {
        Gate::forUser($staff)->authorize('lockAccount', $target);

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        $old = DB::transaction(function () use ($target, $recoverable): string {
            $target->oAuthConnections()->delete();

            $old = $this->detach->execute($target, $recoverable);

            $target->forceFill([
                'password' => null,
                'remember_token' => null,
            ])->save();

            return $old;
        });

        Track::eventSync(
            TrackingEventType::USER_ACCOUNT_LOCK,
            $target,
            ['recoverable' => $recoverable],
            isModerationAction: true,
            reason: $reason,
        );

        Notification::route('mail', $old)
            ->notify(new StaffAccountActionNotification(StaffActionType::AccountLocked, $reason));
    }
}
