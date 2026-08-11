<?php

declare(strict_types=1);

use App\Models\License;
use App\Models\Mod;
use App\Models\ModCategory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);

    Cache::forget('licenses:ordered');

    $this->customLicense = License::query()->firstOrCreate(['name' => License::CUSTOM_NAME], ['link' => '']);
    $this->category = ModCategory::factory()->create();
    $this->user = User::factory()->withMfa()->create();

    $this->actingAs($this->user);
});

/**
 * Fill the create form, leaving the caller to choose the license and the source links.
 *
 * @param  list<string>  $urls
 */
function customLicenseForm(int $licenseId, int $categoryId, array $urls, string $name = 'Custom License Mod'): Testable
{
    $links = [];
    foreach ($urls as $index => $url) {
        $links[] = ['key' => 'link-'.$index, 'url' => $url, 'label' => ''];
    }

    return Livewire::test('pages::mod.create')
        ->set('honeypotData.nameFieldName', 'name')
        ->set('honeypotData.validFromFieldName', 'valid_from')
        ->set('honeypotData.encryptedValidFrom', encrypt(now()->timestamp))
        ->set('name', $name)
        ->set('teaser', 'Test teaser')
        ->set('description', 'Test description')
        ->set('license', (string) $licenseId)
        ->set('category', (string) $categoryId)
        ->set('sourceCodeLinks', $links);
}

/**
 * An unpublished mod owned by the acting user, carrying exactly the given source links.
 */
function customLicenseMod(int $licenseId, int $categoryId, string $url = 'https://github.com/owner/repo'): Mod
{
    $mod = Mod::factory()->create([
        'owner_id' => test()->user->id,
        'license_id' => $licenseId,
        'category_id' => $categoryId,
        'name' => 'Editable Mod',
        'published_at' => null,
    ]);

    $mod->sourceCodeLinks()->delete();
    $mod->sourceCodeLinks()->create(['url' => $url, 'label' => '']);

    return $mod->refresh();
}

/**
 * Open the edit form with the honeypot fields filled in.
 */
function customLicenseEditForm(Mod $mod): Testable
{
    return Livewire::test('pages::mod.edit', ['modId' => $mod->id])
        ->set('honeypotData.nameFieldName', 'name')
        ->set('honeypotData.validFromFieldName', 'valid_from')
        ->set('honeypotData.encryptedValidFrom', encrypt(now()->timestamp));
}

describe('the license row', function (): void {
    it('sorts last, behind names alphabetically greater than its own', function (): void {
        License::factory()->create(['name' => 'Apache-2.0']);
        License::factory()->create(['name' => 'MIT']);
        License::factory()->create(['name' => 'zlib']);

        $names = License::cachedOrdered()->pluck('name')->all();

        expect($names)->toBe(['Apache-2.0', 'MIT', 'zlib', License::CUSTOM_NAME]);
    });

    it('identifies itself through isCustom', function (): void {
        expect($this->customLicense->isCustom())->toBeTrue()
            ->and(License::factory()->create(['name' => 'MIT'])->isCustom())->toBeFalse();
    });

    it('is inserted once and no more, however many times the migration runs', function (): void {
        $migration = require database_path('migrations/2026_08_11_040000_add_custom_license.php');

        $migration->up();
        $migration->up();

        expect(DB::table('licenses')->where('name', License::CUSTOM_NAME)->count())->toBe(1);

        $migration->down();

        expect(DB::table('licenses')->where('name', License::CUSTOM_NAME)->exists())->toBeFalse();
    });
});

describe('creating a mod', function (): void {
    it('makes no outbound request for a normal license', function (): void {
        $license = License::factory()->create(['name' => 'MIT']);

        Http::fake();

        customLicenseForm($license->id, $this->category->id, ['https://github.com/owner/repo'], 'Normal License Mod')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        Http::assertNothingSent();
        expect(Mod::query()->where('name', 'Normal License Mod')->exists())->toBeTrue();
    });

    it('creates the mod when every link carries a non-empty licence file', function (): void {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response("MIT License\n\nCopyright...", 200),
            'gitlab.com/*' => Http::response('All rights reserved.', 200),
        ]);

        customLicenseForm($this->customLicense->id, $this->category->id, [
            'https://github.com/owner/repo',
            'https://gitlab.com/owner/repo',
        ])->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeTrue();

        // One request per link, each for the file at the root of the main branch.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://raw.githubusercontent.com/owner/repo/main/LICENSE.md');
        Http::assertSent(fn ($request): bool => $request->url() === 'https://gitlab.com/owner/repo/-/raw/main/LICENSE.md');
    });

    it('falls back to the master branch when main is missing', function (): void {
        Http::fake([
            'raw.githubusercontent.com/owner/repo/main/*' => Http::response('', 404),
            'raw.githubusercontent.com/owner/repo/master/*' => Http::response('Licence text.', 200),
        ]);

        customLicenseForm($this->customLicense->id, $this->category->id, ['https://github.com/owner/repo'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeTrue();
        Http::assertSentCount(2);
    });

    it('rejects the mod when one link is missing the file', function (): void {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('Licence text.', 200),
            'gitlab.com/*' => Http::response('Not found', 404),
        ]);

        customLicenseForm($this->customLicense->id, $this->category->id, [
            'https://github.com/owner/repo',
            'https://gitlab.com/owner/repo',
        ])->call('save')
            ->assertHasErrors('license')
            ->assertHasNoErrors(['name', 'teaser', 'description', 'sourceCodeLinks']);

        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeFalse();
    });

    it('rejects the mod when the file is present but blank', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response("  \n\t ", 200)]);

        customLicenseForm($this->customLicense->id, $this->category->id, ['https://github.com/owner/repo'])
            ->call('save')
            ->assertHasErrors('license');

        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeFalse();
    });

    it('rejects a codeberg link even though claims allow it', function (): void {
        Http::fake();

        customLicenseForm($this->customLicense->id, $this->category->id, ['https://codeberg.org/owner/repo'])
            ->call('save')
            ->assertHasErrors('license');

        Http::assertNothingSent();
        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeFalse();
    });

    it('rejects a link on an unknown host without touching the network', function (): void {
        Http::fake();

        customLicenseForm($this->customLicense->id, $this->category->id, ['https://git.selfhosted-example.net/owner/repo'])
            ->call('save')
            ->assertHasErrors('license');

        Http::assertNothingSent();
        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeFalse();
    });

    it('reports only the vague message and nothing else on the license field', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response('', 404)]);

        $errors = customLicenseForm($this->customLicense->id, $this->category->id, ['https://github.com/owner/repo'])
            ->call('save')
            ->errors();

        expect($errors->get('license'))->toBe(['There was an error verifying your LICENSE.md file']);
    });
});

describe('editing a mod', function (): void {
    it('does not re-verify an unrelated edit to an already published mod', function (): void {
        // The owner's verification budget is small and the failure message explains nothing, so spending it on edits
        // that changed neither the licence nor the repositories would eventually lock them out of their own mod.
        Http::fake(['raw.githubusercontent.com/*' => Http::response('MIT License', 200)]);

        $mod = customLicenseMod($this->customLicense->id, $this->category->id);
        $mod->forceFill(['published_at' => now()->subDay()])->save();

        Http::fake();

        customLicenseEditForm($mod)
            ->set('teaser', 'A brand new teaser that changes nothing about the licence')
            ->set('publishedAtDate', now()->subDay()->toDateString())
            ->set('publishedAtTime', '12:00')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertNothingSent();

        expect($mod->refresh()->teaser)->toBe('A brand new teaser that changes nothing about the licence');
    });

    it('re-verifies when the source code links change on a published mod', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response('', 404)]);

        $mod = customLicenseMod($this->customLicense->id, $this->category->id);
        $mod->forceFill(['published_at' => now()->subDay()])->save();

        $errors = customLicenseEditForm($mod)
            ->set('sourceCodeLinks', [['key' => 'link-0', 'url' => 'https://github.com/someone/elsewhere', 'label' => '']])
            ->set('publishedAtDate', now()->subDay()->toDateString())
            ->set('publishedAtTime', '12:00')
            ->call('save')
            ->errors();

        expect($errors->get('license'))->toBe(['There was an error verifying your LICENSE.md file']);
    });

    it('refuses to publish and persists nothing when the licence file is gone', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response('', 404)]);

        $mod = customLicenseMod($this->customLicense->id, $this->category->id);

        $errors = customLicenseEditForm($mod)
            ->set('name', 'Renamed Mod')
            ->set('publishedAtDate', now()->toDateString())
            ->set('publishedAtTime', '12:00')
            ->call('save')
            ->errors();

        expect($errors->get('license'))->toBe(['There was an error verifying your LICENSE.md file']);

        $mod->refresh();
        expect($mod->published_at)->toBeNull()
            ->and($mod->name)->toBe('Editable Mod');
    });

    it('publishes when every link still carries the licence file', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response('MIT License', 200)]);

        $mod = customLicenseMod($this->customLicense->id, $this->category->id);

        customLicenseEditForm($mod)
            ->set('publishedAtDate', now()->toDateString())
            ->set('publishedAtTime', '12:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect($mod->refresh()->published_at)->not->toBeNull();
    });

    it('makes no outbound request for a normal license', function (): void {
        $license = License::factory()->create(['name' => 'MIT']);

        Http::fake();

        $mod = customLicenseMod($license->id, $this->category->id);

        customLicenseEditForm($mod)
            ->set('publishedAtDate', now()->toDateString())
            ->set('publishedAtTime', '12:00')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertNothingSent();
        expect($mod->refresh()->published_at)->not->toBeNull();
    });

    it('does not verify a save that leaves the mod unpublished', function (): void {
        Http::fake();

        $mod = customLicenseMod($this->customLicense->id, $this->category->id);

        customLicenseEditForm($mod)
            ->set('name', 'Renamed Mod')
            ->call('save')
            ->assertHasNoErrors();

        Http::assertNothingSent();

        $mod->refresh();
        expect($mod->published_at)->toBeNull()
            ->and($mod->name)->toBe('Renamed Mod');
    });
});

describe('rate limiting', function (): void {
    it('rejects the mod once the per-user limit is spent', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response('Licence text.', 200)]);

        $key = 'custom-license:'.$this->user->id;
        RateLimiter::increment($key, 3600, config()->integer('custom-license.max_attempts'));

        customLicenseForm($this->customLicense->id, $this->category->id, ['https://github.com/owner/repo'])
            ->call('save')
            ->assertHasErrors('license');

        Http::assertNothingSent();
        expect(Mod::query()->where('name', 'Custom License Mod')->exists())->toBeFalse();
    });

    it('charges an attempt for a custom license, successful or not', function (): void {
        Http::fake(['raw.githubusercontent.com/*' => Http::response('Licence text.', 200)]);

        customLicenseForm($this->customLicense->id, $this->category->id, ['https://github.com/owner/repo'])
            ->call('save')
            ->assertHasNoErrors();

        expect(RateLimiter::attempts('custom-license:'.$this->user->id))->toBe(1);
    });

    it('is not charged when a normal license is chosen', function (): void {
        $license = License::factory()->create(['name' => 'MIT']);

        Http::fake();

        customLicenseForm($license->id, $this->category->id, ['https://github.com/owner/repo'], 'Uncharged Mod')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(RateLimiter::attempts('custom-license:'.$this->user->id))->toBe(0);
    });
});
