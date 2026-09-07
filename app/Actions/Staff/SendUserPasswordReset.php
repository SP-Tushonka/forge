<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Facades\Track;
use App\Models\User;
use App\Support\StaffActionLimiter;
use App\Support\UndeliverableAddress;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;

final class SendUserPasswordReset
{
    public function execute(User $staff, User $target, string $reason): void
    {
        Gate::forUser($staff)->authorize('sendPasswordReset', $target);

        if (UndeliverableAddress::check($target->email)) {
            throw StaffActionException::because('This account has no reachable email address. Set a new address first.');
        }

        // sendPasswordResetNotification() returns early for tombstoned accounts,
        // but the broker still reports RESET_LINK_SENT. Catch it here.
        if ($target->email_tombstone !== null) {
            throw StaffActionException::because('This account is archived and cannot receive a reset link. Set a new address first.');
        }

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        $status = Password::sendResetLink(['email' => $target->email]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw StaffActionException::because(match ($status) {
                Password::RESET_THROTTLED => 'A reset link was sent to this account very recently. Wait a minute and try again.',
                Password::INVALID_USER => 'No account matches that email address.',
                default => 'The password reset link could not be sent.',
            });
        }

        Track::eventSync(
            TrackingEventType::USER_PASSWORD_RESET_SENT,
            $target,
            isModerationAction: true,
            reason: $reason,
        );
    }
}
