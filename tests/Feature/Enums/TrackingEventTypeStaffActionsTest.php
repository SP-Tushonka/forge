<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\User;

dataset('staff action event types', [
    TrackingEventType::USER_EMAIL_CHANGE,
    TrackingEventType::USER_EMAIL_REMOVE,
    TrackingEventType::USER_PASSWORD_RESET_SENT,
    TrackingEventType::USER_PASSWORD_INVALIDATE,
    TrackingEventType::USER_MFA_REMOVE,
    TrackingEventType::USER_DISCORD_UNLINK,
    TrackingEventType::USER_ACCOUNT_LOCK,
    TrackingEventType::USER_PHOTO_REMOVE,
]);

it('flags every staff action as a moderation action', function (TrackingEventType $type): void {
    expect($type->isModerationAction())->toBeTrue()
        ->and(TrackingEventType::moderationActions())->toContain($type);
})->with('staff action event types');

it('maps every staff action to the User model', function (TrackingEventType $type): void {
    expect($type->getTrackableModel())->toBe(User::class)
        ->and($type->requiresTrackable())->toBeTrue();
})->with('staff action event types');

it('renders presentation data for every staff action', function (TrackingEventType $type): void {
    expect($type->getName())->not->toBe('')
        ->and($type->getDescription())->not->toBe('')
        ->and($type->getIcon())->not->toBe('')
        ->and($type->getColor())->not->toBe('');
})->with('staff action event types');
