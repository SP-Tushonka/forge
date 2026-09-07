<?php

declare(strict_types=1);

use App\Actions\Staff\LockAccount;
use App\Enums\TrackingEventType;
use App\Models\OAuthConnection;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use App\Support\UndeliverableAddress;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('severs every route back into the account', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => 'victim@example.com',
        'password' => bcrypt('secret'),
        'remember_token' => 'abc123',
    ]);
    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);

    resolve(LockAccount::class)->execute($staff, $target->fresh(), recoverable: true, reason: 'Account hijacked');

    $target->refresh();

    expect($target->password)->toBeNull()
        ->and($target->remember_token)->toBeNull()
        ->and(UndeliverableAddress::check($target->email))->toBeTrue()
        ->and(OAuthConnection::query()->where('user_id', $target->id)->count())->toBe(0);
});

it('notifies the old address', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'victim@example.com']);

    resolve(LockAccount::class)->execute($staff, $target, recoverable: true, reason: 'Account hijacked');

    Notification::assertSentOnDemand(
        StaffAccountActionNotification::class,
        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'victim@example.com',
    );
});

it('writes a tombstone when the lock is recoverable', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'victim@example.com']);

    resolve(LockAccount::class)->execute($staff, $target, recoverable: true, reason: 'Account hijacked');

    expect($target->fresh()->email_tombstone)->toBe(User::emailTombstoneFor('victim@example.com'));
});

it('audits the lock', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'victim@example.com']);

    resolve(LockAccount::class)->execute($staff, $target, recoverable: false, reason: 'Account hijacked');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_ACCOUNT_LOCK->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Account hijacked')
        ->and($event->event_data['recoverable'])->toBeFalse();
});

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->seniorModerator()->create();
    $target = User::factory()->create();

    resolve(LockAccount::class)->execute($actor, $target, recoverable: false, reason: 'nope');
})->throws(AuthorizationException::class);
