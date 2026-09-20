<?php

declare(strict_types=1);

use App\Enums\ModIssueType;
use App\Models\ModIssue;
use App\Models\ModIssueBan;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();
    $this->withoutDefer();
    config()->set('honeypot.enabled', false);

    $this->mod = modWithIssues();
    $this->member = User::factory()->create();
    $this->params = ['modId' => $this->mod->id, 'slug' => $this->mod->slug];
});

it('sends guests to the login page', function (): void {
    $this->get(route('mod.issue.create', $this->params))->assertRedirect(route('login'));
});

it('opens a bug against the latest version and lands on the issue', function (): void {
    $version = $this->mod->versions()->firstOrFail();

    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->assertSeeHtml('data-test="issue-tracker-beta-notice"')
        ->assertSet('affectedVersionId', $version->id)
        ->set('title', 'Crash on raid start')
        ->set('body', 'It crashes every time I load into Customs.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('mod.issue.show', [...$this->params, 'number' => 1]));

    $issue = ModIssue::query()->sole();

    expect($issue->user_id)->toBe($this->member->id)
        ->and($issue->affected_mod_version_id)->toBe($version->id);
});

it('needs a version for a bug but none for a feature request', function (): void {
    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('title', 'Crash on raid start')
        ->set('body', 'It crashes every time I load into Customs.')
        ->set('affectedVersionId', null)
        ->call('save')
        ->assertHasErrors(['affectedVersionId' => 'required'])
        ->set('type', ModIssueType::Feature->value)
        ->call('save')
        ->assertHasNoErrors();
});

it('offers only the types the mod accepts and rejects the rest', function (): void {
    $this->mod->update(['disabled_issue_types' => [ModIssueType::Question->value]]);

    $page = Livewire::actingAs($this->member)->test('pages::mod-issue.create', $this->params);

    $page->assertSee('Compatibility')
        ->assertDontSee('Question')
        ->set('type', ModIssueType::Question->value)
        ->set('title', 'How do I configure this?')
        ->set('body', 'I cannot work out where the config file lives.')
        ->call('save')
        ->assertHasErrors('type');

    expect(ModIssue::query()->count())->toBe(0);
});

it('lets a question go without a version but makes a compatibility report name one', function (): void {
    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('type', ModIssueType::Question->value)
        ->set('affectedVersionId', null)
        ->set('title', 'How do I configure this?')
        ->set('body', 'I cannot work out where the config file lives.')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('type', ModIssueType::Compatibility->value)
        ->set('affectedVersionId', null)
        ->set('title', 'Conflicts with another mod')
        ->set('body', 'Both mods patch the same loot table and the raid never loads.')
        ->call('save')
        ->assertHasErrors(['affectedVersionId' => 'required']);

    expect(ModIssue::query()->sole()->type)->toBe(ModIssueType::Question);
});

it('swaps the untouched bug template out for a feature request', function (): void {
    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('type', ModIssueType::Feature->value)
        ->assertSet('body', '');
});

it('refuses the template submitted as-is', function (): void {
    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('title', 'Crash on raid start')
        ->call('save')
        ->assertHasErrors(['body' => 'not_in']);
});

it('rejects a version belonging to another mod', function (): void {
    $foreign = modWithIssues()->versions()->firstOrFail();

    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('title', 'Crash on raid start')
        ->set('body', 'It crashes every time I load into Customs.')
        ->set('affectedVersionId', $foreign->id)
        ->call('save')
        ->assertHasErrors(['affectedVersionId' => 'in']);
});

it('forbids banned members, and every member once issues are off', function (): void {
    ModIssueBan::factory()->for($this->mod)->for($this->member, 'user')->create();

    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->assertForbidden();

    $this->mod->update(['issues_enabled' => false]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::mod-issue.create', $this->params)
        ->assertForbidden();
});

it('opens one issue when the form is submitted twice', function (): void {
    // A double click queues a second save() behind the first; Livewire runs it on the same, already-submitted form.
    Livewire::actingAs($this->mod->owner)
        ->test('pages::mod-issue.create', $this->params)
        ->set('title', 'Crash on raid start')
        ->set('body', 'It crashes every time I load into Customs.')
        ->call('save')
        ->call('save')
        ->assertHasNoErrors();

    expect(ModIssue::query()->count())->toBe(1);
});

it('rate limits members', function (): void {
    foreach (range(1, 5) as $attempt) {
        Livewire::actingAs($this->member)
            ->test('pages::mod-issue.create', $this->params)
            ->set('title', 'Crash number '.$attempt)
            ->set('body', 'It crashes every time I load into Customs.')
            ->call('save')
            ->assertHasNoErrors();
    }

    Livewire::actingAs($this->member)
        ->test('pages::mod-issue.create', $this->params)
        ->set('title', 'One report too many')
        ->set('body', 'It crashes every time I load into Customs.')
        ->call('save')
        ->assertHasErrors('title');

    expect(ModIssue::query()->count())->toBe(5);
});
