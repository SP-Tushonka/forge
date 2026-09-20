<?php

declare(strict_types=1);

use App\Enums\ModIssueType;
use App\Models\License;
use App\Models\Mod;
use App\Models\ModIssueBan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->withoutDefer();
    config()->set('honeypot.enabled', false);
});

it('switches issues on from the edit page', function (): void {
    $license = License::factory()->create();
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::mod.edit', ['modId' => $mod->id])
        ->assertSeeHtml('data-test="issue-tracker-beta-notice"')
        ->assertSet('issuesEnabled', false)
        ->set('name', 'Issue Tracking Mod')
        ->set('guid', 'com.issue.tracking')
        ->set('teaser', 'A teaser')
        ->set('description', 'A description')
        ->set('sourceCodeLinks.0.url', 'https://github.com/example/repo')
        ->set('sourceCodeLinks.0.label', '')
        ->set('license', (string) $license->id)
        ->set('issuesEnabled', true)
        ->call('save')
        ->assertHasNoErrors();

    expect($mod->fresh()?->issues_enabled)->toBeTrue();
});

it('stores the types the owner unticked, and only those', function (): void {
    $license = License::factory()->create();
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create(['issues_enabled' => true]);

    Livewire::actingAs($owner)
        ->test('pages::mod.edit', ['modId' => $mod->id])
        ->assertSet('allowedIssueTypes', array_map(fn (ModIssueType $type): string => $type->value, ModIssueType::cases()))
        ->set('name', 'Issue Tracking Mod')
        ->set('guid', 'com.issue.tracking')
        ->set('teaser', 'A teaser')
        ->set('description', 'A description')
        ->set('sourceCodeLinks.0.url', 'https://github.com/example/repo')
        ->set('sourceCodeLinks.0.label', '')
        ->set('license', (string) $license->id)
        ->set('allowedIssueTypes', [ModIssueType::Bug->value, ModIssueType::Compatibility->value])
        ->call('save')
        ->assertHasNoErrors();

    $mod->refresh();

    expect($mod->disabled_issue_types)->toBe([ModIssueType::Feature->value, ModIssueType::Question->value])
        ->and($mod->allowsIssueType(ModIssueType::Bug))->toBeTrue()
        ->and($mod->allowsIssueType(ModIssueType::Feature))->toBeFalse();
});

it('refuses to leave the tracker on with no type accepted', function (): void {
    $license = License::factory()->create();
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create(['issues_enabled' => true]);

    Livewire::actingAs($owner)
        ->test('pages::mod.edit', ['modId' => $mod->id])
        ->set('name', 'Issue Tracking Mod')
        ->set('guid', 'com.issue.tracking')
        ->set('teaser', 'A teaser')
        ->set('description', 'A description')
        ->set('sourceCodeLinks.0.url', 'https://github.com/example/repo')
        ->set('sourceCodeLinks.0.label', '')
        ->set('license', (string) $license->id)
        ->set('allowedIssueTypes', [])
        ->call('save')
        ->assertHasErrors('allowedIssueTypes');
});

it('shows the no-types-left error once, not once per checkbox', function (): void {
    $license = License::factory()->create();
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create(['issues_enabled' => true]);

    $html = Livewire::actingAs($owner)
        ->test('pages::mod.edit', ['modId' => $mod->id])
        ->set('name', 'Issue Tracking Mod')
        ->set('guid', 'com.issue.tracking')
        ->set('teaser', 'A teaser')
        ->set('description', 'A description')
        ->set('sourceCodeLinks.0.url', 'https://github.com/example/repo')
        ->set('sourceCodeLinks.0.label', '')
        ->set('license', (string) $license->id)
        ->set('allowedIssueTypes', [])
        ->call('save')
        ->html();

    expect(mb_substr_count($html, 'Keep at least one issue type enabled'))->toBe(1);
});

it('shows the type checkboxes only while the tracker is on', function (): void {
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::mod.edit', ['modId' => $mod->id])
        ->assertDontSee('Issue types you accept')
        ->set('issuesEnabled', true)
        ->assertSee('Issue types you accept');
});

// blur never fires on the group element (focus is on the inner checkboxes, and blur does not bubble), so a .blur
// binding here would leave the server holding the old list: the save then "succeeds" having changed nothing.
it('binds the type checkboxes without the blur modifier', function (): void {
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create(['issues_enabled' => true]);

    Livewire::actingAs($owner)
        ->test('pages::mod.edit', ['modId' => $mod->id])
        ->assertSeeHtml('wire:model.self="allowedIssueTypes"')
        ->assertDontSeeHtml('wire:model.blur.self="allowedIssueTypes"');
});

it('lists active bans to the mod managers and lifts them', function (): void {
    $mod = modWithIssues();
    $banned = User::factory()->create(['name' => 'Spammer']);
    $ban = ModIssueBan::factory()->for($mod)->for($banned, 'user')->create(['reason' => 'Link spam']);

    Livewire::actingAs($mod->owner)
        ->test('mod.issue-bans', ['modId' => $mod->id])
        ->assertSee('Spammer')
        ->assertSee('Link spam')
        ->call('unban', $ban->id);

    expect($mod->isIssueBanned($banned))->toBeFalse();
});

it('keeps the ban list from other members', function (): void {
    $mod = modWithIssues();

    Livewire::actingAs(User::factory()->create())
        ->test('mod.issue-bans', ['modId' => $mod->id])
        ->assertForbidden();
});
