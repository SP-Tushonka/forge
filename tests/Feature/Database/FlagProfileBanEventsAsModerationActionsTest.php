<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;

describe('Flag profile ban events as moderation actions migration', function (): void {
    it('flags unflagged ban and unban events and recovers the ban reason', function (): void {
        $staff = User::factory()->admin()->create();
        $target = User::factory()->create();

        $target->ban(['created_by_type' => User::class, 'created_by_id' => $staff->id, 'comment' => 'Harassing a mod author']);

        $event = fn (TrackingEventType $type): TrackingEvent => TrackingEvent::factory()->create([
            'event_name' => $type->value,
            'visitor_type' => User::class,
            'visitor_id' => $staff->id,
            'visitable_type' => User::class,
            'visitable_id' => $target->id,
            'is_moderation_action' => false,
            'created_at' => now(),
        ]);

        $ban = $event(TrackingEventType::USER_BAN);
        $unban = $event(TrackingEventType::USER_UNBAN);
        $login = $event(TrackingEventType::LOGIN);

        $migration = require database_path('migrations/2026_10_27_000001_flag_profile_ban_events_as_moderation_actions.php');
        $migration->up();

        expect($ban->fresh())
            ->is_moderation_action->toBeTrue()
            ->reason->toBe('Harassing a mod author')
            ->and($unban->fresh())
            ->is_moderation_action->toBeTrue()
            ->reason->toBeNull()
            ->and($login->fresh()->is_moderation_action)->toBeFalse();
    });
});
