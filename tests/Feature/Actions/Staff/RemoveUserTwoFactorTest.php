<?php

declare(strict_types=1);

use App\Actions\Staff\RemoveUserTwoFactor;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('clears every two factor column', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->withMfa()->create();

    expect($target->two_factor_secret)->not->toBeNull();

    resolve(RemoveUserTwoFactor::class)->execute($staff, $target, 'User lost their phone');

    $target->refresh();

    expect($target->two_factor_secret)->toBeNull()
        ->and($target->two_factor_recovery_codes)->toBeNull()
        ->and($target->two_factor_confirmed_at)->toBeNull();

    Notification::assertSentTo($target, StaffAccountActionNotification::class);
});

it('audits the removal as a moderation action', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->withMfa()->create();

    resolve(RemoveUserTwoFactor::class)->execute($staff, $target, 'User lost their phone');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_MFA_REMOVE->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('User lost their phone');
});

it('refuses when the user has no two factor set up', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    resolve(RemoveUserTwoFactor::class)->execute($staff, $target, 'nothing to remove');
})->throws(StaffActionException::class);

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->seniorModerator()->create();
    $target = User::factory()->withMfa()->create();

    resolve(RemoveUserTwoFactor::class)->execute($actor, $target, 'nope');
})->throws(AuthorizationException::class);
