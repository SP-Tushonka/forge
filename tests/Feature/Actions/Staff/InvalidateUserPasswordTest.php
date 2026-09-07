<?php

declare(strict_types=1);

use App\Actions\Staff\InvalidateUserPassword;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\OAuthConnection;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('nulls the password and the remember token', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => 'real@example.com',
        'password' => bcrypt('secret'),
        'remember_token' => 'abc123',
    ]);

    resolve(InvalidateUserPassword::class)->execute($staff, $target, 'Account compromised');

    $target->refresh();

    expect($target->password)->toBeNull()
        ->and($target->remember_token)->toBeNull();

    Notification::assertSentTo($target, StaffAccountActionNotification::class);
});

it('audits the invalidation', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com', 'password' => bcrypt('secret')]);

    resolve(InvalidateUserPassword::class)->execute($staff, $target, 'Account compromised');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_PASSWORD_INVALIDATE->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Account compromised');
});

it('refuses when it would lock the account out', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => '77@unclaimed.invalid',
        'password' => bcrypt('secret'),
    ]);

    resolve(InvalidateUserPassword::class)->execute($staff, $target, 'would lock out');
})->throws(StaffActionException::class);

it('allows it when the user still has an OAuth connection', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => '77@unclaimed.invalid',
        'password' => bcrypt('secret'),
    ]);

    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);

    resolve(InvalidateUserPassword::class)->execute($staff, $target->fresh(), 'still has discord');

    expect($target->fresh()->password)->toBeNull();
});

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->moderator()->create();
    $target = User::factory()->create(['password' => bcrypt('secret')]);

    resolve(InvalidateUserPassword::class)->execute($actor, $target, 'nope');
})->throws(AuthorizationException::class);
