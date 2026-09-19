<?php

declare(strict_types=1);

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
