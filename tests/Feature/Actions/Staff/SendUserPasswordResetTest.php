<?php

declare(strict_types=1);

use App\Actions\Staff\SendUserPasswordReset;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\ResetPassword;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('sends a reset link and audits it', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);

    resolve(SendUserPasswordReset::class)->execute($staff, $target, 'User asked for help');

    Notification::assertSentTo($target, ResetPassword::class);

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_PASSWORD_RESET_SENT->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('User asked for help');
});

it('refuses when the address is undeliverable', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => '42@unclaimed.invalid']);

    resolve(SendUserPasswordReset::class)->execute($staff, $target, 'no address');
})->throws(StaffActionException::class);

it('refuses when the account is tombstoned', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => 'real@example.com',
        'email_tombstone' => User::emailTombstoneFor('real@example.com'),
    ]);

    resolve(SendUserPasswordReset::class)->execute($staff, $target, 'tombstoned');
})->throws(StaffActionException::class);

it('refuses when the broker throttles a second attempt', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);

    $action = resolve(SendUserPasswordReset::class);
    $action->execute($staff, $target, 'first');
    $action->execute($staff, $target, 'second');
})->throws(StaffActionException::class);

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->moderator()->create();
    $target = User::factory()->create();

    resolve(SendUserPasswordReset::class)->execute($actor, $target, 'nope');
})->throws(AuthorizationException::class);
