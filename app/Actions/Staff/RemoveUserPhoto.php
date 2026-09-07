<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\StaffActionType;
use App\Enums\TrackingEventType;
use App\Enums\UserImageType;
use App\Exceptions\StaffActionException;
use App\Facades\Track;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use App\Support\StaffActionLimiter;
use Illuminate\Support\Facades\Gate;

final class RemoveUserPhoto
{
    public function execute(User $staff, User $target, UserImageType $image, string $reason): void
    {
        Gate::forUser($staff)->authorize('removePhotos', $target);

        if (StaffActionLimiter::tooManyAttempts($staff)) {
            throw StaffActionException::because('Too many staff actions. Try again in '.StaffActionLimiter::availableIn($staff).' seconds.');
        }

        StaffActionLimiter::hit($staff);

        match ($image) {
            UserImageType::ProfilePhoto => $target->deleteProfilePhoto(),
            UserImageType::CoverPhoto => $target->deleteCoverPhoto(),
        };

        Track::eventSync(
            TrackingEventType::USER_PHOTO_REMOVE,
            $target,
            ['image' => $image->value],
            isModerationAction: true,
            reason: $reason,
        );

        $target->notify(new StaffAccountActionNotification(StaffActionType::PhotoRemoved, $reason));
    }
}
