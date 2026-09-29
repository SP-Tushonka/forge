<?php

declare(strict_types=1);

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();
    Queue::fake();
    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());

    $this->library = Mod::factory()->create(['published_at' => now()->subDay()]);
    ModVersion::factory()->for($this->library)->create(['spt_version_constraint' => '1.0.0', 'published_at' => now()->subDay()]);

    $this->dependent = function (string $name, int $downloads = 0, ?Mod $mod = null): Mod {
        $mod ??= Mod::factory()->create(['name' => $name, 'published_at' => now()->subDay()]);
        $version = ModVersion::factory()->for($mod)->create([
            'spt_version_constraint' => '1.0.0',
            'disabled' => false,
            'published_at' => now()->subDay(),
        ]);
        Dependency::factory()->forModVersion($version)->create(['dependent_mod_id' => $this->library->id]);

        // Written raw so version observers recalculating the total cannot overwrite the fixture.
        Mod::query()->withoutGlobalScopes()->whereKey($mod->id)->update(['downloads' => $downloads]);

        return $mod;
    };

    $this->release = function (Mod $mod, string $version, string $spt, bool $dependsOnLibrary = true, ?CarbonInterface $createdAt = null): ModVersion {
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $version));
        $modVersion = ModVersion::factory()->for($mod)->create([
            'version' => $version,
            'version_major' => $major,
            'version_minor' => $minor,
            'version_patch' => $patch,
            'version_labels' => '',
            'spt_version_constraint' => $spt,
            'disabled' => false,
            'published_at' => now()->subDays(30),
            'created_at' => $createdAt ?? now()->subDays(30),
        ]);

        if ($dependsOnLibrary) {
            Dependency::factory()->forModVersion($modVersion)->create(['dependent_mod_id' => $this->library->id]);
        }

        return $modVersion;
    };
});

it('lists dependent mods ordered by downloads', function (): void {
    ($this->dependent)('Less Popular Dependent', 10);
    ($this->dependent)('Most Popular Dependent', 500);

    Livewire::withoutLazyLoading()
        ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
        ->assertSeeInOrder(['Most Popular Dependent', 'Less Popular Dependent'])
        ->assertSuccessful();
});

it('hides unpublished dependent mods', function (): void {
    ($this->dependent)('Hidden Dependent', mod: Mod::factory()->unpublished()->create(['name' => 'Hidden Dependent']));
    ($this->dependent)('Visible Dependent');

    Livewire::withoutLazyLoading()
        ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
        ->assertSee('Visible Dependent')
        ->assertDontSee('Hidden Dependent');
});

it('paginates ten dependents per page', function (): void {
    foreach (range(1, 10) as $i) {
        ($this->dependent)('Page One Dependent '.$i, 100 + $i);
    }

    ($this->dependent)('Page Two Dependent', 1);

    Livewire::withoutLazyLoading()
        ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
        ->assertSee('Page One Dependent 10')
        ->assertDontSee('Page Two Dependent')
        ->call('gotoPage', 2, 'dependentPage')
        ->assertSee('Page Two Dependent');
});

it('hides the filter bar when nothing has ever depended on the mod', function (): void {
    Livewire::withoutLazyLoading()
        ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
        ->assertSee('No published mod currently depends on this one.')
        ->assertDontSeeHtml('id="dependents-spt-filter"');
});

describe('SPT version filter', function (): void {
    beforeEach(function (): void {
        SptVersion::factory()->create(['version' => '3.11.4']);
        SptVersion::factory()->create(['version' => '4.0.0']);
    });

    it('lists the version of each dependent that supports the selected SPT version', function (): void {
        $sain = Mod::factory()->create(['name' => 'Dependent Sain', 'published_at' => now()->subDay()]);
        ($this->release)($sain, '3.2.1', '>=3.11.4');
        ($this->release)($sain, '4.4.3', '4.0.0');
        ($this->release)(Mod::factory()->create(['name' => 'Dependent Modern', 'published_at' => now()->subDay()]), '1.0.0', '4.0.0');

        $component = Livewire::withoutLazyLoading()
            ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
            ->assertSee(['Dependent Sain', 'v4.4.3', 'Dependent Modern'])
            ->set('sptVersion', '3.11.4')
            ->assertSee(['Dependent Sain', 'v3.2.1'])
            ->assertDontSee('v4.4.3')
            ->assertDontSee('Dependent Modern');

        expect($component->instance()->badgeSptVersions->get($sain->id)?->version)->toBe('3.11.4');
    });

    it('finds a mod under an older SPT version after it dropped the dependency', function (): void {
        ($this->release)(Mod::factory()->create(['name' => 'Dependent Current', 'published_at' => now()->subDay()]), '1.0.0', '4.0.0');
        $former = Mod::factory()->create(['name' => 'Dependent Former', 'published_at' => now()->subDay()]);
        ($this->release)($former, '1.0.0', '3.11.4');
        ($this->release)($former, '2.0.0', '4.0.0', dependsOnLibrary: false);

        Livewire::withoutLazyLoading()
            ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
            ->assertSee('Dependent Current')
            ->assertDontSee('Dependent Former')
            ->set('sptVersion', '3.11.4')
            ->assertSee(['Dependent Former', 'v1.0.0'])
            ->assertDontSee('Dependent Current');
    });

    it('only offers SPT versions with a dependent and drops a stale one from the URL', function (): void {
        ($this->release)(Mod::factory()->create(['published_at' => now()->subDay()]), '1.0.0', '4.0.0');
        $dropped = Mod::factory()->create(['published_at' => now()->subDay()]);
        ($this->release)($dropped, '1.0.0', '3.11.4');
        ($this->release)($dropped, '1.1.0', '3.11.4', dependsOnLibrary: false);

        $component = Livewire::withQueryParams(['dependentsSpt' => '3.11.4'])
            ->withoutLazyLoading()
            ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
            ->assertSet('sptVersion', '');

        expect($component->instance()->sptVersionOptions->pluck('version')->all())->toBe(['4.0.0']);
    });

    it('applies the filter and sort from the URL, dropping an unknown sort', function (): void {
        ($this->release)(Mod::factory()->create(['name' => 'Dependent Old', 'published_at' => now()->subDay()]), '1.0.0', '3.11.4');
        ($this->release)(Mod::factory()->create(['name' => 'Dependent New', 'published_at' => now()->subDay()]), '1.0.0', '4.0.0');

        Livewire::withQueryParams(['dependentsSpt' => '3.11.4', 'dependentsSort' => 'name'])
            ->withoutLazyLoading()
            ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
            ->assertSet('sptVersion', '3.11.4')
            ->assertSet('sort', 'name')
            ->assertSee('Dependent Old')
            ->assertDontSee('Dependent New');

        Livewire::withQueryParams(['dependentsSort' => 'bogus'])
            ->withoutLazyLoading()
            ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
            ->assertSet('sort', 'downloads');
    });

    it('resets to the first page when the filter or sort changes', function (): void {
        foreach (range(1, 11) as $i) {
            ($this->release)(Mod::factory()->create(['published_at' => now()->subDay()]), '1.0.0', '3.11.4');
        }

        Livewire::withoutLazyLoading()
            ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
            ->call('gotoPage', 2, 'dependentPage')
            ->set('sptVersion', '3.11.4')
            ->assertSet('paginators.dependentPage', 1)
            ->call('gotoPage', 2, 'dependentPage')
            ->set('sort', 'name')
            ->assertSet('paginators.dependentPage', 1);
    });
});

it('sorts dependents', function (string $sort, array $expected): void {
    SptVersion::factory()->create(['version' => '3.11.4']);
    SptVersion::factory()->create(['version' => '4.0.0']);

    $dependents = [
        'Dependent Alpha' => ['downloads' => 10, 'created' => 3, 'released' => 1, 'spt' => '3.11.4'],
        'Dependent Bravo' => ['downloads' => 500, 'created' => 1, 'released' => 5, 'spt' => '3.11.4'],
        'Dependent Charlie' => ['downloads' => 50, 'created' => 5, 'released' => 3, 'spt' => '4.0.0'],
    ];

    foreach ($dependents as $name => $fixture) {
        $mod = Mod::factory()->create(['name' => $name, 'published_at' => now()->subDays(10), 'created_at' => now()->subDays($fixture['created'])]);
        ($this->release)($mod, '1.0.0', $fixture['spt'], createdAt: now()->subDays($fixture['released']));
        Mod::query()->withoutGlobalScopes()->whereKey($mod->id)->update(['downloads' => $fixture['downloads']]);
    }

    Livewire::withoutLazyLoading()
        ->test('mod.show.dependents-tab', ['modId' => $this->library->id])
        ->set('sort', $sort)
        ->assertSeeInOrder($expected);
})->with([
    'download count' => ['downloads', ['Dependent Bravo', 'Dependent Charlie', 'Dependent Alpha']],
    'recently updated' => ['updated', ['Dependent Alpha', 'Dependent Charlie', 'Dependent Bravo']],
    'newest' => ['created', ['Dependent Bravo', 'Dependent Alpha', 'Dependent Charlie']],
    'name' => ['name', ['Dependent Alpha', 'Dependent Bravo', 'Dependent Charlie']],
    'SPT version, then downloads' => ['spt', ['Dependent Charlie', 'Dependent Bravo', 'Dependent Alpha']],
    'unknown value falls back to download count' => ['bogus', ['Dependent Bravo', 'Dependent Charlie', 'Dependent Alpha']],
]);
