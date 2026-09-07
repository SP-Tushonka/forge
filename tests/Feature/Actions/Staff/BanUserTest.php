<?php

declare(strict_types=1);

use App\Actions\Staff\BanUser;
use App\Actions\Staff\UnbanUser;
use App\Enums\TrackingEventType;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\UserBannedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('bans, notifies and records a moderation action', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    resolve(BanUser::class)->execute($staff, $target, '7_days', 'Spamming');

    expect($target->fresh()->isBanned())->toBeTrue();

    Notification::assertSentTo($target, UserBannedNotification::class);

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_BAN->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Spamming');
});

it('bans permanently without an expiry', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    resolve(BanUser::class)->execute($staff, $target, 'permanent', 'Repeat offender');

    expect($target->fresh()->bans()->sole()->expired_at)->toBeNull();
});

it('refuses to ban another staff member', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();

    resolve(BanUser::class)->execute($staff, $target, '7_days', 'nope');
})->throws(AuthorizationException::class);

it('refuses a regular user', function (): void {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    resolve(BanUser::class)->execute($actor, $target, '7_days', 'nope');
})->throws(AuthorizationException::class);

it('unbans and records a moderation action', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();
    $target->ban();

    resolve(UnbanUser::class)->execute($staff, $target, 'Appeal accepted');

    expect($target->fresh()->isBanned())->toBeFalse();

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_UNBAN->value)->sole();

    expect($event->is_moderation_action)->toBeTrue();
});

it('refuses an unauthorized unban', function (): void {
    $actor = User::factory()->create();
    $target = User::factory()->create();
    $target->ban();

    resolve(UnbanUser::class)->execute($actor, $target, 'nope');
})->throws(AuthorizationException::class);
