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

final class RemoveUserTwoFactor
{
    public function execute(User $staff, User $target, string $reason): void
    {
        Gate::forUser($staff)->authorize('removeTwoFactor', $target);

        if ($target->two_factor_secret === null) {
            throw StaffActionException::because('This account does not have Forge two-factor authentication enabled.');
        }

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        DB::transaction(function () use ($target): void {
            $target->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();
        });

        Track::eventSync(
            TrackingEventType::USER_MFA_REMOVE,
            $target,
            isModerationAction: true,
            reason: $reason,
        );

        $target->notify(new StaffAccountActionNotification(StaffActionType::TwoFactorRemoved, $reason));
    }
}
