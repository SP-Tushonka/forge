<?php

declare(strict_types=1);

use App\Actions\Staff\RemoveUserEmail;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('notifies the old address, not the placeholder', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);

    resolve(RemoveUserEmail::class)->execute($staff, $target, recoverable: false, reason: 'Abuse');

    Notification::assertSentOnDemand(
        StaffAccountActionNotification::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'real@example.com',
    );
});

it('audits the removal without logging the address', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);

    resolve(RemoveUserEmail::class)->execute($staff, $target, recoverable: true, reason: 'Support request');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_EMAIL_REMOVE->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Support request')
        ->and(json_encode($event->event_data))->not->toContain('real@example.com');
});

it('records recoverability on the event', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    resolve(RemoveUserEmail::class)->execute($staff, $target, recoverable: true, reason: 'Support request');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_EMAIL_REMOVE->value)->sole();

    expect($event->event_data['recoverable'])->toBeTrue();
});

it('refuses when the address is already detached', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => '999@unclaimed.invalid']);

    resolve(RemoveUserEmail::class)->execute($staff, $target, recoverable: false, reason: 'again');
})->throws(StaffActionException::class);

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->moderator()->create();
    $target = User::factory()->create();

    resolve(RemoveUserEmail::class)->execute($actor, $target, recoverable: false, reason: 'nope');
})->throws(AuthorizationException::class);
