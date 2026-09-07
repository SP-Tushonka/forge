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
use Illuminate\Support\Facades\Notification;

final class RemoveUserEmail
{
    public function __construct(private readonly DetachUserEmail $detach) {}

    public function execute(User $staff, User $target, bool $recoverable, string $reason): void
    {
        Gate::forUser($staff)->authorize('removeEmail', $target);

        if (UndeliverableAddress::check($target->email)) {
            throw StaffActionException::because('This account already has no reachable email address.');
        }

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        $old = DB::transaction(fn (): string => $this->detach->execute($target, $recoverable));

        Track::eventSync(
            TrackingEventType::USER_EMAIL_REMOVE,
            $target,
            ['recoverable' => $recoverable],
            isModerationAction: true,
            reason: $reason,
        );

        // The notification is queued and re-fetches the notifiable by id, which
        // would resolve the placeholder. Send to the captured address instead.
        Notification::route('mail', $old)
            ->notify(new StaffAccountActionNotification(StaffActionType::EmailRemoved, $reason));
    }
}
