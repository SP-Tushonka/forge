<?php

declare(strict_types=1);

use App\Models\License;
use App\Models\Mod;
use App\Models\ModCategory;
use App\Models\SourceCodeLink;
use App\Models\User;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);
});

describe('License selection', function (): void {
    it('saves license value when changed via the listbox', function (): void {
        $owner = User::factory()->withMfa()->create();
        $originalLicense = License::factory()->create(['name' => 'Original License']);
        $newLicense = License::factory()->create(['name' => 'MIT License']);
        ModCategory::factory()->create();

        $mod = Mod::factory()->for($owner, 'owner')->create([
            'license_id' => $originalLicense->id,
        ]);

        SourceCodeLink::factory()->create([
            'sourceable_type' => Mod::class,
            'sourceable_id' => $mod->id,
        ]);

        $this->actingAs($owner);

        $page = visit(route('mod.edit', ['modId' => $mod->id]));

        $page->assertSee('Edit Mod')
            ->assertNoJavascriptErrors()
            ->click('Original License')
            ->waitForText('MIT License')
            ->click('MIT License')
            ->click('Update Mod')
            // The edit page already renders the mod name, so waiting on it would not wait for the save round-trip.
            // The redirect only happens once save() has committed.
            ->assertPathIs(route('mod.show', [$mod->id, $mod->slug], absolute: false));

        $mod->refresh();
        expect($mod->license_id)->toBe($newLicense->id);
    });
});
