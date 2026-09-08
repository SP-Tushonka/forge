<?php

declare(strict_types=1);

use App\Actions\Staff\ClearModOwnership;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\Mod;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\ModOwnershipChangedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('clears the owner and records a moderation action', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(ClearModOwnership::class)->execute($staff, $mod, false, 'Account was compromised');

    expect($mod->fresh()->owner_id)->toBeNull();

    $event = TrackingEvent::query()
        ->where('event_name', TrackingEventType::MOD_OWNERSHIP_CLEARED->value)
        ->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Account was compromised');
});

it('notifies the previous owner', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(ClearModOwnership::class)->execute($staff, $mod, false, 'Cleared');

    Notification::assertSentTo($owner, ModOwnershipChangedNotification::class);
});

it('keeps the previous owner as an additional author when asked', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(ClearModOwnership::class)->execute($staff, $mod, true, 'Cleared');

    expect($mod->fresh()->additionalAuthors->pluck('id')->all())->toContain($owner->id);
});

it('refuses when the mod is already unowned', function (): void {
    $staff = User::factory()->admin()->create();
    $mod = Mod::factory()->create(['owner_id' => null]);

    resolve(ClearModOwnership::class)->execute($staff, $mod, false, 'nope');
})->throws(StaffActionException::class, 'This mod has no owner to clear.');

it('refuses a moderator', function (): void {
    $moderator = User::factory()->moderator()->create();
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(ClearModOwnership::class)->execute($moderator, $mod, false, 'nope');
})->throws(AuthorizationException::class);

it('refuses a regular user', function (): void {
    $actor = User::factory()->create();
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    resolve(ClearModOwnership::class)->execute($actor, $mod, false, 'nope');
})->throws(AuthorizationException::class);
