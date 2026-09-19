<?php

declare(strict_types=1);

use App\Enums\IssueNotificationLevel;
use App\Models\User;
use Livewire\Livewire;

it('saves the issue notification level as soon as it changes', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('profile.notification-preferences')
        ->assertSet('issueNotifications', 'all')
        ->set('issueNotifications', 'bell');

    expect($user->fresh()?->issue_notifications)->toBe(IssueNotificationLevel::Bell);
});

it('falls back to all for an unknown level', function (): void {
    $user = User::factory()->create(['issue_notifications' => IssueNotificationLevel::Off]);

    Livewire::actingAs($user)
        ->test('profile.notification-preferences')
        ->set('issueNotifications', 'loud');

    expect($user->fresh()?->issue_notifications)->toBe(IssueNotificationLevel::All);
});
