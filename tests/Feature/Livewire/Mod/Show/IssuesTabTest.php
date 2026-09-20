<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Models\ModIssue;
use App\Models\ModIssueBan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->withoutDefer();

    $this->mod = modWithIssues();
});

it('lists open issues for guests, with closed ones behind the toggle and deleted ones nowhere', function (): void {
    ModIssue::factory()->for($this->mod)->create(['title' => 'Open crash']);
    ModIssue::factory()->for($this->mod)->status(ModIssueStatus::Completed)->create(['title' => 'Fixed crash']);
    ModIssue::factory()->for($this->mod)->create(['title' => 'Deleted crash'])->delete();

    Livewire::withoutLazyLoading()
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->assertSeeHtml('data-test="issue-tracker-beta-notice"')
        ->assertSee('Open crash')
        ->assertDontSee('Fixed crash')
        ->assertDontSee('Deleted crash')
        ->set('state', 'closed')
        ->assertSee('Fixed crash')
        ->assertDontSee('Open crash');
});

it('filters by type and searches titles', function (): void {
    ModIssue::factory()->for($this->mod)->create(['title' => 'Crash when looting']);
    ModIssue::factory()->for($this->mod)->feature()->create(['title' => 'Add a config option']);

    Livewire::withoutLazyLoading()
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->set('type', ModIssueType::Feature->value)
        ->assertSee('Add a config option')
        ->assertDontSee('Crash when looting')
        ->set('type', '')
        ->set('search', 'loot')
        ->assertSee('Crash when looting')
        ->assertDontSee('Add a config option');
});

it('drops a switched-off type from the filter unless issues were already filed under it', function (): void {
    $this->mod->update(['disabled_issue_types' => [ModIssueType::Question->value, ModIssueType::Compatibility->value]]);
    ModIssue::factory()->for($this->mod)->create(['type' => ModIssueType::Question, 'title' => 'An older question']);

    Livewire::withoutLazyLoading()
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->assertSee('Question')
        ->assertDontSee('Compatibility');
});

it('forbids the tab to members once issues are off, but not to the owner', function (): void {
    $this->mod->update(['issues_enabled' => false]);

    Livewire::withoutLazyLoading()
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->assertForbidden();

    Livewire::withoutLazyLoading()
        ->actingAs($this->mod->owner)
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->assertSee('Issues are switched off for this mod');
});

it('offers New issue to members and explains why to banned ones', function (): void {
    $banned = User::factory()->create();
    ModIssueBan::factory()->for($this->mod)->for($banned, 'user')->create();

    Livewire::withoutLazyLoading()
        ->actingAs(User::factory()->create())
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->assertSeeHtml('data-test="new-issue-button"');

    Livewire::withoutLazyLoading()
        ->actingAs($banned)
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id])
        ->assertDontSeeHtml('data-test="new-issue-button"')
        ->assertSee("You can't open issues on this mod.");
});

it('mutes and unmutes the mod issues', function (): void {
    $member = User::factory()->create();

    $tab = Livewire::withoutLazyLoading()
        ->actingAs($member)
        ->test('mod.show.issues-tab', ['modId' => $this->mod->id]);

    $tab->call('toggleModMute');
    expect($member->hasMuted($this->mod))->toBeTrue();

    $tab->call('toggleModMute');
    expect($member->hasMuted($this->mod))->toBeFalse();
});

it('shows the Issues tab with the open count only to viewers who may see it', function (): void {
    ModIssue::factory()->for($this->mod)->count(2)->create();

    $this->get($this->mod->detail_url)
        ->assertSeeHtml('data-test="issues-tab"')
        ->assertSee('2 Issues');

    $this->mod->update(['issues_enabled' => false]);

    $this->get($this->mod->detail_url)->assertDontSeeHtml('data-test="issues-tab"');
});
