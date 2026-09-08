<?php

declare(strict_types=1);

use App\Actions\Staff\RemoveUserPhoto;
use App\Enums\TrackingEventType;
use App\Enums\UserImageType;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;

it('removes the profile photo and audits it', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['profile_photo_path' => 'avatars/x.png']);

    resolve(RemoveUserPhoto::class)->execute($staff, $target, UserImageType::ProfilePhoto, 'Inappropriate avatar');

    expect($target->fresh()->profile_photo_path)->toBeNull();

    $event = TrackingEvent::query()->where('event_name', TrackingEventType::USER_PHOTO_REMOVE->value)->sole();

    expect($event->is_moderation_action)->toBeTrue()
        ->and($event->reason)->toBe('Inappropriate avatar');

    Notification::assertSentTo($target, StaffAccountActionNotification::class);
});

it('removes the cover photo', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['cover_photo_path' => 'covers/x.png']);

    resolve(RemoveUserPhoto::class)->execute($staff, $target, UserImageType::CoverPhoto, 'Inappropriate banner');

    expect($target->fresh()->cover_photo_path)->toBeNull();
});

it('refuses a non-staff actor', function (): void {
    $actor = User::factory()->moderator()->create();
    $target = User::factory()->create(['profile_photo_path' => 'avatars/x.png']);

    resolve(RemoveUserPhoto::class)->execute($actor, $target, UserImageType::ProfilePhoto, 'nope');
})->throws(AuthorizationException::class);

it('refuses acting on another staff member', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->admin()->create(['profile_photo_path' => 'avatars/x.png']);

    resolve(RemoveUserPhoto::class)->execute($staff, $target, UserImageType::ProfilePhoto, 'nope');
})->throws(AuthorizationException::class);
