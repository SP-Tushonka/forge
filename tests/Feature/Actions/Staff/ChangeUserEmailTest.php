<?php

declare(strict_types=1);

use App\Actions\Staff\ChangeUserEmail;
use App\Enums\TrackingEventType;
use App\Models\DisposableEmailBlocklist;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

it('changes the address and clears verification', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'old@example.com']);

    resolve(ChangeUserEmail::class)->execute($staff, $target, 'new@example.com', 'Support request');

    $target->refresh();

    expect($target->email)->toBe('new@example.com')
        ->and($target->email_verified_at)->toBeNull();
});

it('notifies both the old and the new address', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'old@example.com']);

    resolve(ChangeUserEmail::class)->execute($staff, $target, 'new@example.com', 'Support request');

    Notification::assertSentOnDemand(
        StaffAccountActionNotification::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'old@example.com',
    );

    Notification::assertSentTo($target, StaffAccountActionNotification::class);
});

it('rejects an address already in use', function (): void {
    $staff = User::factory()->admin()->create();
    User::factory()->create(['email' => 'taken@example.com']);
    $target = User::factory()->create();

    resolve(ChangeUserEmail::class)->execute($staff, $target, 'taken@example.com', 'Support request');
})->throws(ValidationException::class);

it('rejects a disposable address', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    DisposableEmailBlocklist::factory()->create(['domain' => 'throwaway.example']);

    resolve(ChangeUserEmail::class)->execute($staff, $target, 'burner@throwaway.example', 'Support request');
})->throws(ValidationException::class);

it('rejects an address belonging to an archived account', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    User::factory()->create([
        'email' => '111@unclaimed.invalid',
        'email_tombstone' => User::emailTombstoneFor('archived@example.com'),
    ]);

    resolve(ChangeUserEmail::class)->execute($staff, $target, 'archived@example.com', 'Support request');
})->throws(ValidationException::class);

it('audits without logging either address', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'old@example.com']);

    resolve(ChangeUserEmail::class)->execute($staff, $target, 'new@example.com', 'Support request');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_EMAIL_CHANGE->value)->sole();
    $payload = json_encode($event->event_data);

    // The action itself logs no addresses. TrackService adds User::getTrackingSnapshot()
    // after the mutation, so the current address appears (as it does for every user event
    // in the app) but the replaced one is never recorded.
    expect($event->is_moderation_action)->toBeTrue()
        ->and($payload)->not->toContain('old@example.com');
});

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->moderator()->create();
    $target = User::factory()->create();

    resolve(ChangeUserEmail::class)->execute($actor, $target, 'new@example.com', 'nope');
})->throws(AuthorizationException::class);
