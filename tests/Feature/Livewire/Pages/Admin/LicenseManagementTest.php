<?php

declare(strict_types=1);

use App\Models\License;
use App\Models\Mod;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

function licenseAdmin(): User
{
    return User::factory()->admin()->create();
}

/**
 * Licenses other than the one the migrations seed, which every test starts with.
 *
 * @return Illuminate\Database\Eloquent\Builder<License>
 */
function addedLicenses(): Illuminate\Database\Eloquent\Builder
{
    return License::query()->whereNot('name', License::CUSTOM_NAME);
}

describe('LicenseManagement authorization', function (): void {
    it('denies access to guests', function (): void {
        $this->get(route('admin.licenses'))
            ->assertRedirect(route('login'));
    });

    it('denies access to regular users', function (): void {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.licenses'))
            ->assertForbidden();
    });

    it('denies access to moderators', function (): void {
        $this->actingAs(User::factory()->moderator()->create())
            ->get(route('admin.licenses'))
            ->assertForbidden();
    });

    it('allows access to administrators', function (): void {
        $this->actingAs(licenseAdmin())
            ->get(route('admin.licenses'))
            ->assertOk();
    });

    it('refuses to mount for a non-staff user', function (): void {
        Livewire::actingAs(User::factory()->create())
            ->test('pages::admin.license-management')
            ->assertForbidden();
    });

    // The component action is reachable over the shared Livewire update endpoint, so it re-checks the gate itself
    // rather than trusting the route middleware that gated the original page load.
    it('refuses the create action once the session is no longer staff', function (string $role): void {
        $component = Livewire::actingAs(licenseAdmin())->test('pages::admin.license-management');

        $this->actingAs(User::factory()->{$role}()->create());

        $component
            ->set('formName', 'Sneaky License')
            ->set('formLink', 'https://example.com/sneaky')
            ->call('createLicense')
            ->assertForbidden();

        expect(License::query()->where('name', 'Sneaky License')->exists())->toBeFalse();
    })->with(['moderator', 'seniorModerator']);
});

describe('LicenseManagement listing', function (): void {
    it('lists licenses ordered by name', function (): void {
        License::factory()->create(['name' => 'Zlib License']);
        License::factory()->create(['name' => 'Apache 2.0']);

        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->assertSeeInOrder(['Apache 2.0', 'Zlib License']);
    });

    it('counts unpublished and disabled mods against the license', function (): void {
        $license = License::factory()->create(['name' => 'Counted License']);
        $empty = License::factory()->create(['name' => 'Aardvark License']);
        Mod::factory()->create(['license_id' => $license->id]);
        Mod::factory()->unpublished()->create(['license_id' => $license->id]);
        Mod::factory()->disabled()->create(['license_id' => $license->id]);

        $licenses = Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->instance()
            ->licenses;

        expect($licenses->firstWhere('name', 'Counted License')->mods_count)->toBe(3)
            ->and($licenses->firstWhere('name', 'Aardvark License')->mods_count)->toBe(0)
            ->and($empty->hub_id)->toBeNull();
    });
});

describe('LicenseManagement creation', function (): void {
    it('creates a license and leaves hub_id null', function (): void {
        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->call('showCreateLicense')
            ->set('formName', 'MIT License')
            ->set('formLink', 'https://opensource.org/license/mit')
            ->call('createLicense')
            ->assertHasNoErrors()
            ->assertSet('showCreateModal', false)
            ->assertSet('formName', '')
            ->assertSet('formLink', '');

        $license = License::query()->where('name', 'MIT License')->sole();

        expect($license->hub_id)->toBeNull()
            ->and($license->link)->toBe('https://opensource.org/license/mit');
    });

    it('exposes a new license through the ordered cache', function (): void {
        License::factory()->create(['name' => 'Apache 2.0']);

        expect(License::cachedOrdered()->pluck('name')->all())->toBe(['Apache 2.0', License::CUSTOM_NAME]);
        expect(Cache::has('licenses:ordered'))->toBeTrue();

        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', 'BSD 3-Clause')
            ->set('formLink', 'https://opensource.org/license/bsd-3-clause')
            ->call('createLicense')
            ->assertHasNoErrors();

        expect(License::cachedOrdered()->pluck('name')->all())->toBe(['Apache 2.0', 'BSD 3-Clause', License::CUSTOM_NAME]);
    });

    it('rejects a duplicate name', function (): void {
        License::factory()->create(['name' => 'MIT License']);

        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', 'MIT License')
            ->set('formLink', 'https://opensource.org/license/mit')
            ->call('createLicense')
            ->assertHasErrors('formName');

        expect(License::query()->where('name', 'MIT License')->count())->toBe(1);
    });

    it('rejects a duplicate name that differs only by case', function (): void {
        License::factory()->create(['name' => 'MIT License']);

        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', 'mit license')
            ->set('formLink', 'https://opensource.org/license/mit')
            ->call('createLicense')
            ->assertHasErrors('formName');

        expect(addedLicenses()->count())->toBe(1);
    });

    it('squishes surrounding and repeated whitespace out of the name', function (): void {
        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', '  MIT   License  ')
            ->set('formLink', '  https://opensource.org/license/mit  ')
            ->call('createLicense')
            ->assertHasNoErrors();

        $license = addedLicenses()->sole();

        expect($license->name)->toBe('MIT License')
            ->and($license->link)->toBe('https://opensource.org/license/mit');
    });

    it('treats a whitespace variant of an existing name as a duplicate', function (): void {
        License::factory()->create(['name' => 'MIT License']);

        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', '  MIT   License ')
            ->set('formLink', 'https://opensource.org/license/mit')
            ->call('createLicense')
            ->assertHasErrors('formName');

        expect(addedLicenses()->count())->toBe(1);
    });

    it('escapes the license name and hardens the outbound link', function (): void {
        License::factory()->create([
            'name' => 'Evil <script>alert(1)</script> License',
            'link' => 'https://example.com/terms',
        ]);

        $html = Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->html();

        expect($html)->not->toContain('<script>alert(1)</script>')
            ->and($html)->toContain('&lt;script&gt;')
            ->and($html)->toContain('rel="noopener noreferrer"');
    });

    it('rejects a missing or non-http link', function (string $link): void {
        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', 'Some License')
            ->set('formLink', $link)
            ->call('createLicense')
            ->assertHasErrors('formLink');

        expect(License::query()->where('name', 'Some License')->exists())->toBeFalse();
    })->with([
        'empty' => '',
        'not a url' => 'opensource.org/license/mit',
        'javascript scheme' => 'javascript:alert(1)',
        'ftp scheme' => 'ftp://example.com/license.txt',
    ]);

    it('requires a name', function (): void {
        Livewire::actingAs(licenseAdmin())
            ->test('pages::admin.license-management')
            ->set('formName', '')
            ->set('formLink', 'https://opensource.org/license/mit')
            ->call('createLicense')
            ->assertHasErrors(['formName' => 'required']);
    });
});
