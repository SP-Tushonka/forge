<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Actions\Fortify\GuardsArchivedAccounts;
use App\Enums\StaffActionType;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Facades\Track;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use App\Rules\NotDisposableEmail;
use App\Services\AccountRecoveryService;
use App\Support\StaffActionLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ChangeUserEmail
{
    use GuardsArchivedAccounts;

    public function __construct(
        private readonly AccountRecoveryService $accountRecovery = new AccountRecoveryService,
    ) {}

    public function execute(User $staff, User $target, string $newEmail, string $reason): void
    {
        Gate::forUser($staff)->authorize('changeEmail', $target);

        Validator::make(['email' => $newEmail], [
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($target->id), new NotDisposableEmail],
        ])->validate();

        $this->guardArchivedAccount($newEmail);

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        $old = $target->email;

        DB::transaction(function () use ($target, $newEmail): void {
            $target->forceFill([
                'email' => $newEmail,
                'email_verified_at' => null,
            ])->save();
        });

        Track::eventSync(
            TrackingEventType::USER_EMAIL_CHANGE,
            $target,
            isModerationAction: true,
            reason: $reason,
        );

        Notification::route('mail', $old)
            ->notify(new StaffAccountActionNotification(StaffActionType::EmailChanged, $reason));

        $target->notify(new StaffAccountActionNotification(StaffActionType::EmailChanged, $reason));

        $target->sendEmailVerificationNotification();
    }
}
