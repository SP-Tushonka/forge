<?php

declare(strict_types=1);

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
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
