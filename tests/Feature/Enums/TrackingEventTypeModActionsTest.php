<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Mod;

dataset('mod ownership event types', [
    TrackingEventType::MOD_OWNERSHIP_TRANSFER,
    TrackingEventType::MOD_OWNERSHIP_CLEARED,
]);

it('is a moderation action', function (TrackingEventType $type): void {
    expect($type->isModerationAction())->toBeTrue()
        ->and(TrackingEventType::moderationActions())->toContain($type);
})->with('mod ownership event types');

it('is trackable against a mod', function (TrackingEventType $type): void {
    expect($type->getTrackableModel())->toBe(Mod::class)
        ->and($type->requiresTrackable())->toBeTrue();
})->with('mod ownership event types');

it('has a name, description, icon and colour', function (TrackingEventType $type): void {
    expect($type->getName())->not->toBe('')
        ->and($type->getDescription())->not->toBe('')
        ->and($type->getIcon())->not->toBe('')
        ->and($type->getColor())->not->toBe('');
})->with('mod ownership event types');

it('is private, like every other moderation event', function (TrackingEventType $type): void {
    expect($type->isPrivate())->toBeTrue();
})->with('mod ownership event types');

it('surfaces staff mod edits and deletions on the moderation log', function (): void {
    // The moderation-actions page filters on moderationActions() AND the is_moderation_action
    // column. Before this change these two were flagged on the row but filtered out by name.
    expect(TrackingEventType::MOD_EDIT->isModerationAction())->toBeTrue()
        ->and(TrackingEventType::MOD_DELETE->isModerationAction())->toBeTrue()
        ->and(TrackingEventType::moderationActions())->toContain(TrackingEventType::MOD_EDIT)
        ->and(TrackingEventType::moderationActions())->toContain(TrackingEventType::MOD_DELETE);
});
