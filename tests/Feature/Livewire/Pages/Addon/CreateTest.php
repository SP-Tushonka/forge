<?php

declare(strict_types=1);

use App\Jobs\GenerateThumbnailVariants;
use App\Models\Addon;
use App\Models\License;
use App\Models\Mod;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);
});

describe('creation flow pages', function (): void {
    it('renders the addon guidelines page', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->addonsEnabled()->create();

        $this->actingAs($user)
            ->get(route('addon.guidelines', $mod->id))
            ->assertOk();
    });

    it('renders the addon path-check page', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->addonsEnabled()->create();

        $this->actingAs($user)
            ->get(route('addon.path-check', $mod->id))
            ->assertOk();
    });

    it('routes users from guidelines to path-check after acknowledgment', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->addonsEnabled()->create();

        $this->actingAs($user);

        Livewire::test('pages::addon.guidelines-acknowledgment', ['mod' => $mod])
            ->call('agree')
            ->assertRedirect(route('addon.path-check', ['mod' => $mod->id]));
    });

    it('routes users from path-check to addon create on proceed', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->addonsEnabled()->create();

        $this->actingAs($user);

        Livewire::test('pages::addon.path-check', ['mod' => $mod])
            ->call('proceed')
            ->assertRedirect(route('addon.create', ['mod' => $mod->id]));
    });

    it('blocks unauthorized users from the addon path-check page', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->create(['addons_disabled' => true]);

        $this->actingAs($user)
            ->get(route('addon.path-check', $mod->id))
            ->assertForbidden();
    });

    it('renders the addon version create page', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->addonsEnabled()->create();
        $addon = Addon::factory()->published()->recycle($mod)->for($user, 'owner')->create();

        $this->actingAs($user)
            ->get(route('addon.version.create', $addon->id))
            ->assertOk();
    });
});

describe('authorization', function (): void {
    it('prevents creating addon for mod with addons disabled', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->for($user, 'owner')->create([
            'addons_disabled' => true,
        ]);

        $this->actingAs($user);

        expect($user->can('create', [Addon::class, $mod]))->toBeFalse();
    });

    it('allows any user with MFA to create addon', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->create();

        $this->actingAs($user);

        expect($user->can('create', [Addon::class, $mod]))->toBeTrue();
    });
});

describe('component rendering', function (): void {
    it('can mount and render the create component', function (): void {
        $user = User::factory()->withMfa()->create();
        $mod = Mod::factory()->for($user, 'owner')->create();
        $this->actingAs($user);

        Livewire::test('pages::addon.create', ['mod' => $mod])
            ->assertOk();
    });
});

describe('thumbnail variants', function (): void {
    beforeEach(function (): void {
        Storage::fake(config('filesystems.asset_upload', 'public'));
        $this->user = User::factory()->withMfa()->create();
        $this->license = License::factory()->create();
        $this->mod = Mod::factory()->addonsEnabled()->create();
        $this->actingAs($this->user);
    });

    it('dispatches thumbnail variant generation when a thumbnail is uploaded', function (): void {
        Queue::fake();

        Livewire::test('pages::addon.create', ['mod' => $this->mod])
            ->set('honeypotData.nameFieldName', 'name')
            ->set('honeypotData.validFromFieldName', 'valid_from')
            ->set('honeypotData.encryptedValidFrom', encrypt(now()->timestamp))
            ->set('name', 'Thumbnail Variant Addon')
            ->set('teaser', 'Test teaser')
            ->set('description', 'Test description')
            ->set('license', (string) $this->license->id)
            ->set('sourceCodeLinks.0.url', 'https://github.com/test/repo')
            ->set('sourceCodeLinks.0.label', '')
            ->set('containsAds', false)
            ->set('thumbnail', UploadedFile::fake()->image('thumbnail.png', 512, 512))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $addon = Addon::query()->where('name', 'Thumbnail Variant Addon')->firstOrFail();
        Queue::assertPushed(fn (GenerateThumbnailVariants $job): bool => $job->model->is($addon));
    });

    it('does not dispatch thumbnail variant generation without a thumbnail', function (): void {
        Queue::fake();

        Livewire::test('pages::addon.create', ['mod' => $this->mod])
            ->set('honeypotData.nameFieldName', 'name')
            ->set('honeypotData.validFromFieldName', 'valid_from')
            ->set('honeypotData.encryptedValidFrom', encrypt(now()->timestamp))
            ->set('name', 'No Thumbnail Addon')
            ->set('teaser', 'Test teaser')
            ->set('description', 'Test description')
            ->set('license', (string) $this->license->id)
            ->set('sourceCodeLinks.0.url', 'https://github.com/test/repo')
            ->set('sourceCodeLinks.0.label', '')
            ->set('containsAds', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        Queue::assertNotPushed(GenerateThumbnailVariants::class);
    });
});
