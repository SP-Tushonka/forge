<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\User;
use App\Notifications\UserBannedNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;

final class BanUser
{
    public function execute(User $staff, User $target, string $duration, ?string $reason): void
    {
        Gate::forUser($staff)->authorize('ban', $target);

        $attributes = [
            'created_by_type' => User::class,
            'created_by_id' => $staff->id,
            'comment' => $reason !== '' ? $reason : null,
        ];

        if ($duration !== 'permanent') {
            $attributes['expired_at'] = $this->expiry($duration);
        }

        $ban = $target->ban($attributes);

        $target->notify(new UserBannedNotification($ban));

        // USER_BAN is the moderator's action and belongs in the moderation log.
        // USER_BANNED is the counterpart record on the user and is not one.
        Track::eventSync(TrackingEventType::USER_BAN, $target, isModerationAction: true, reason: $reason);
        Track::event(TrackingEventType::USER_BANNED, $target);
    }

    private function expiry(string $duration): CarbonInterface
    {
        return match ($duration) {
            '1_hour' => now()->addHour(),
            '24_hours' => now()->addDay(),
            '7_days' => now()->addWeek(),
            '30_days' => now()->addMonth(),
            default => now()->addHour(),
        };
    }
}
