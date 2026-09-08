<?php

declare(strict_types=1);

use App\Actions\Staff\UnlinkDiscordConnection;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Models\OAuthConnection;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('deletes the discord connection', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);
    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);

    resolve(UnlinkDiscordConnection::class)->execute($staff, $target->fresh(), 'User asked to unlink');

    expect(OAuthConnection::query()->where('user_id', $target->id)->count())->toBe(0);

    Notification::assertSentTo($target, StaffAccountActionNotification::class);
});

it('leaves other providers alone', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);
    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);
    OAuthConnection::factory()->for($target)->create(['provider' => 'github']);

    resolve(UnlinkDiscordConnection::class)->execute($staff, $target->fresh(), 'User asked to unlink');

    expect(OAuthConnection::query()->where('user_id', $target->id)->pluck('provider')->all())->toBe(['github']);
});

it('audits the unlink', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);
    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);

    resolve(UnlinkDiscordConnection::class)->execute($staff, $target->fresh(), 'User asked to unlink');

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_DISCORD_UNLINK->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('User asked to unlink');
});

it('refuses when there is no discord connection', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'real@example.com']);

    resolve(UnlinkDiscordConnection::class)->execute($staff, $target, 'nothing to unlink');
})->throws(StaffActionException::class);

it('refuses when it would lock the account out', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => '88@unclaimed.invalid', 'password' => null]);
    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);

    resolve(UnlinkDiscordConnection::class)->execute($staff, $target->fresh(), 'would lock out');
})->throws(StaffActionException::class);

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->moderator()->create();
    $target = User::factory()->create();
    OAuthConnection::factory()->for($target)->create(['provider' => 'discord']);

    resolve(UnlinkDiscordConnection::class)->execute($actor, $target->fresh(), 'nope');
})->throws(AuthorizationException::class);
