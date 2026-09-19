<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Models\Emoji;
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
    $this->reporter = User::factory()->create();
    $this->issue = ModIssue::factory()->for($this->mod)->for($this->reporter, 'user')->create(['title' => 'Crash on raid start']);
    $this->params = ['modId' => $this->mod->id, 'slug' => $this->mod->slug, 'number' => $this->issue->number];
});

it('shows the issue to guests', function (): void {
    $this->get(route('mod.issue.show', $this->params))
        ->assertOk()
        ->assertSee('Crash on raid start')
        ->assertSee('The Issue Tracker is currently in beta');
});

// Separate tests: a mount that 404s never dehydrates, which leaves Livewire's redirector bound in the test app's
// container for the next request.
it('404s an unknown number', function (): void {
    $this->get(route('mod.issue.show', [...$this->params, 'number' => 99]))->assertNotFound();
});

it('fixes a stale slug', function (): void {
    $this->get(route('mod.issue.show', [...$this->params, 'slug' => 'old-name']))
        ->assertRedirect(route('mod.issue.show', $this->params));
});

it('shows a deleted issue to its managers only', function (): void {
    $this->issue->delete();

    $this->get(route('mod.issue.show', $this->params))->assertForbidden();

    $this->actingAs($this->mod->owner)
        ->get(route('mod.issue.show', $this->params))
        ->assertOk()
        ->assertSee('This issue has been deleted');
});

it('lets the reporter close and reopen their own issue', function (): void {
    $page = Livewire::actingAs($this->reporter)->test('pages::mod-issue.show', $this->params);

    $page->call('close');
    expect($this->issue->fresh()?->status)->toBe(ModIssueStatus::Closed);

    $page->call('reopen');
    expect($this->issue->fresh()?->status)->toBe(ModIssueStatus::New);
});

it('stops other members closing the issue', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::mod-issue.show', $this->params)
        ->call('close')
        ->assertForbidden();
});

it('lets the reporter edit and marks the issue edited', function (): void {
    Livewire::actingAs($this->reporter)
        ->test('pages::mod-issue.show', $this->params)
        ->call('startEditing')
        ->set('editTitle', 'Crash when the raid loads')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSet('editing', false);

    $fresh = $this->issue->fresh();

    expect($fresh?->title)->toBe('Crash when the raid loads')
        ->and($fresh?->edited_at)->not->toBeNull();
});

it('stops the mod owner rewriting a reporter issue', function (): void {
    Livewire::actingAs($this->mod->owner)
        ->test('pages::mod-issue.show', $this->params)
        ->call('startEditing')
        ->assertForbidden();

    expect($this->mod->owner->can('update', $this->issue))->toBeFalse()
        // The owner still moderates the issue; only rewriting its text is off limits.
        ->and($this->mod->owner->can('manage', $this->issue))->toBeTrue();
});

it('lets a moderator edit any issue', function (): void {
    $moderator = User::factory()->moderator()->create();

    Livewire::actingAs($moderator)
        ->test('pages::mod-issue.show', $this->params)
        ->call('startEditing')
        ->set('editTitle', 'Crash on raid start, moderated')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($this->issue->fresh()?->title)->toBe('Crash on raid start, moderated');
});

// The edit form's markdown editor calls this on whatever component renders it; without it, Preview threw
// MethodNotFoundException.
it('renders a markdown preview for the edit form', function (): void {
    $component = Livewire::actingAs($this->reporter)->test('pages::mod-issue.show', $this->params);

    expect($component->instance()->previewMarkdown('**Bold** text', 'comments'))
        ->toContain('<strong>Bold</strong>');
});

it('mutes the issue when a follower unsubscribes', function (): void {
    $follower = User::factory()->create();
    $this->issue->subscribeUser($follower);

    Livewire::actingAs($follower)
        ->test('pages::mod-issue.show', $this->params)
        ->call('toggleSubscription');

    expect($follower->hasMuted($this->issue))->toBeTrue();
});

it('takes reactions on the issue itself', function (): void {
    $heart = Emoji::query()->where('shortcode', 'heart')->sole();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::mod-issue.show', $this->params)
        ->call('toggleReaction', 'issue', $this->issue->id, $heart->id);

    expect($this->issue->reactions()->count())->toBe(1);
});
