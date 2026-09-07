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

final class InvalidateUserPassword
{
    public function execute(User $staff, User $target, string $reason): void
    {
        Gate::forUser($staff)->authorize('invalidatePassword', $target);

        $target->loadMissing('oAuthConnections');

        if (UndeliverableAddress::check($target->email) && $target->oAuthConnections->isEmpty()) {
            throw StaffActionException::because('This would permanently lock the account out: it has no reachable email address and no linked login. Set an email address first, or use Lock Account if that is the intent.');
        }

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        // AuthenticateSession logs other sessions out once the password hash
        // changes. remember_token must go too, since remember-me bypasses that.
        DB::transaction(function () use ($target): void {
            $target->forceFill([
                'password' => null,
                'remember_token' => null,
            ])->save();
        });

        Track::eventSync(
            TrackingEventType::USER_PASSWORD_INVALIDATE,
            $target,
            isModerationAction: true,
            reason: $reason,
        );

        $target->notify(new StaffAccountActionNotification(StaffActionType::PasswordInvalidated, $reason));
    }
}
