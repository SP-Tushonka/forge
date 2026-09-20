<?php

declare(strict_types=1);

use App\Enums\ModIssueType;
use App\Models\Mod;
use App\Models\User;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);
});

/**
 * The type checkboxes bind through Flux's group element, which never emits blur, so a deferred-on-blur binding
 * would leave the server holding the old list: the save then reports success having changed nothing. Only a real
 * browser exercises that path.
 */
it('refuses to save the mod with every issue type unticked', function (): void {
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create(['issues_enabled' => true]);

    $this->actingAs($owner);

    $page = visit(route('mod.edit', ['modId' => $mod->id]))
        ->on()->desktop()
        ->waitForText('Issue types you accept');

    foreach (ModIssueType::cases() as $type) {
        $page->click(sprintf('ui-checkbox[value="%s"]', $type->value));
    }

    $page->press('Update Mod')
        ->assertSee('Keep at least one issue type enabled');

    expect($mod->fresh()?->disabled_issue_types)->toBeNull();
});

it('saves the types that are left ticked', function (): void {
    $owner = User::factory()->withMfa()->create();
    $mod = Mod::factory()->recycle($owner)->create(['issues_enabled' => true]);

    $this->actingAs($owner);

    $page = visit(route('mod.edit', ['modId' => $mod->id]))
        ->on()->desktop()
        ->waitForText('Issue types you accept');

    $page->click(sprintf('ui-checkbox[value="%s"]', ModIssueType::Question->value))
        ->click(sprintf('ui-checkbox[value="%s"]', ModIssueType::Feature->value))
        ->press('Update Mod');

    waitForWrite($page, fn (): bool => $mod->fresh()?->disabled_issue_types !== null);

    expect($mod->fresh()?->disabled_issue_types)
        ->toBe([ModIssueType::Feature->value, ModIssueType::Question->value]);
});
