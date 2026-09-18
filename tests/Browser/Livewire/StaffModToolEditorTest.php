<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\User;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);
});

it('edits a mod description in the markdown editor and previews it', function (): void {
    $staff = User::factory()->admin()->create();
    $mod = Mod::factory()->create(['description' => 'Staff will rewrite this.']);
    $field = 'textarea[name="description"]';

    $this->actingAs($staff);

    $page = visit(route('admin.staff-tools', ['modId' => $mod->id]).'#mods')
        ->on()->desktop()
        ->waitForText('Save details')
        ->assertValue($field, 'Staff will rewrite this.')
        ->clear($field)
        ->type($field, 'Rewritten by **staff**.')
        ->click('Preview');

    // Bold text can only come from the server's renderer, never from the typed Markdown being echoed back.
    $page->assertScript("document.querySelector('.user-markdown strong')?.textContent", 'staff');

    $page->click('Write')
        // Not the bare name: the Users tab, hidden on this page, has a reason field of its own.
        ->type('@mod-tool-reason', 'Fixing the description.')
        ->click('Save details');

    waitForWrite($page, fn (): bool => $mod->fresh()->description === 'Rewritten by **staff**.');

    expect($mod->fresh()->description)->toBe('Rewritten by **staff**.');

    $page->assertNoJavaScriptErrors();
});
