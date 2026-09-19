<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    $this->withoutDefer();

    $this->mod = modWithIssues();
    $this->owner = $this->mod->owner;
    $this->issue = ModIssue::factory()->for($this->mod)->create();
});

it('refuses anyone but managers and staff', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('mod-issue.manage-panel', ['issueId' => $this->issue->id])
        ->assertForbidden();
});

it('moves the issue along and tells the page', function (): void {
    Livewire::actingAs($this->owner)
        ->test('mod-issue.manage-panel', ['issueId' => $this->issue->id])
        ->set('status', ModIssueStatus::InProgress->value)
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->assertDispatched('mod-issue-updated');

    expect($this->issue->fresh()?->status)->toBe(ModIssueStatus::InProgress);
});

it('asks which issue a duplicate duplicates', function (): void {
    Livewire::actingAs($this->owner)
        ->test('mod-issue.manage-panel', ['issueId' => $this->issue->id])
        ->set('status', ModIssueStatus::Duplicate->value)
        ->call('saveStatus')
        ->assertHasErrors('duplicateOfId');
});

it('saves a normalised fixed version and rejects nonsense', function (): void {
    Livewire::actingAs($this->owner)
        ->test('mod-issue.manage-panel', ['issueId' => $this->issue->id])
        ->set('fixedVersion', 'v2.0.0')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSet('fixedVersion', '2.0.0')
        ->set('fixedVersion', 'next week')
        ->call('saveDetails')
        ->assertHasErrors('fixedVersion');
});

it('locks and deletes, and lets staff restore', function (): void {
    $panel = Livewire::actingAs($this->owner)->test('mod-issue.manage-panel', ['issueId' => $this->issue->id]);

    $panel->call('toggleLock');
    expect($this->issue->fresh()?->isLocked())->toBeTrue();

    $panel->call('deleteIssue');
    expect($this->issue->fresh()?->trashed())->toBeTrue();

    Livewire::actingAs(User::factory()->moderator()->create())
        ->test('mod-issue.manage-panel', ['issueId' => $this->issue->id])
        ->call('restoreIssue');

    expect($this->issue->fresh()?->trashed())->toBeFalse();
});
