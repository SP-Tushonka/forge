<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->withoutDefer();
});

it('lists the completed issues a version fixes', function (): void {
    $mod = modWithIssues();
    $fixed = ModIssue::factory()->for($mod)->status(ModIssueStatus::Completed)->create(['fixed_version' => '1.0.0']);
    $stillOpen = ModIssue::factory()->for($mod)->create(['fixed_version' => '1.0.0']);

    Livewire::withoutLazyLoading()
        ->test('mod.show.versions-tab', ['modId' => $mod->id])
        ->assertSeeHtml('data-test="version-fixed-issues"')
        ->assertSeeHtml(e($fixed->url()))
        ->assertDontSeeHtml(e($stillOpen->url()));
});

it('leaves the list off for viewers who cannot see the issues', function (): void {
    $mod = modWithIssues(['issues_enabled' => false]);
    ModIssue::factory()->for($mod)->status(ModIssueStatus::Completed)->create(['fixed_version' => '1.0.0']);

    Livewire::withoutLazyLoading()
        ->test('mod.show.versions-tab', ['modId' => $mod->id])
        ->assertDontSeeHtml('data-test="version-fixed-issues"');
});
