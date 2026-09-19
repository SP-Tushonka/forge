<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->withoutDefer();

    $this->mod = modWithIssues();
    $this->owner = $this->mod->owner;
});

it('bans a member from the mod issues for a set time', function (): void {
    $member = User::factory()->create();

    Livewire::actingAs($this->owner)
        ->test('mod-issue.ban-modal', ['modId' => $this->mod->id])
        ->dispatch('ban-from-issues', userId: $member->id)
        ->set('duration', '7')
        ->set('reason', 'Keeps posting spam links.')
        ->call('ban')
        ->assertHasNoErrors()
        ->assertDispatched('mod-issue-updated');

    $ban = $this->mod->issueBans()->sole();

    expect($ban->user_id)->toBe($member->id)
        ->and($ban->reason)->toBe('Keeps posting spam links.')
        ->and($ban->expires_at?->isSameDay(now()->addDays(7)))->toBeTrue();
});

it('refuses to ban a co-author', function (): void {
    $author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($author);

    Livewire::actingAs($this->owner)
        ->test('mod-issue.ban-modal', ['modId' => $this->mod->id])
        ->dispatch('ban-from-issues', userId: $author->id)
        ->assertForbidden();
});
