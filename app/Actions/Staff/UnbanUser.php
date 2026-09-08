<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UnbanUser
{
    public function execute(User $staff, User $target, ?string $reason): void
    {
        Gate::forUser($staff)->authorize('unban', $target);

        $target->unban();

        Track::eventSync(TrackingEventType::USER_UNBAN, $target, isModerationAction: true, reason: $reason);
        Track::event(TrackingEventType::USER_UNBANNED, $target);
    }
}
