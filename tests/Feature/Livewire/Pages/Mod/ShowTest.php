<?php

declare(strict_types=1);

use App\Models\Dependency;
use App\Models\DependencyResolved;
use App\Models\Mod;
use App\Models\ModList;
use App\Models\ModListItem;
use App\Models\ModUserDownload;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();
});

/**
 * Create a publicly visible mod with a published version pinned to a known SPT version so the mod always resolves to
 * publicly viewable. Without pinning the constraint the factory's random value can leave the mod hidden.
 *
 * @param  array<string, mixed>  $attributes
 */
function createVisibleModForShow(array $attributes = []): Mod
{
    $sptVersion = SptVersion::query()->firstOrCreate(
        ['version' => '3.9.0'],
        SptVersion::factory()->make(['version' => '3.9.0'])->toArray(),
    );

    $mod = Mod::factory()->create($attributes);

    $modVersion = ModVersion::factory()->create([
        'mod_id' => $mod->id,
        'published_at' => now()->subDay(),
        'spt_version_constraint' => '>=3.0.0',
    ]);
    $modVersion->sptVersions()->sync($sptVersion->id);

    return $mod;
}

/**
 * Add the given mod to a list as an active (or tombstoned) item.
 *
 * @param  array<string, mixed>  $attributes
 */
function addModToList(Mod $mod, ModList $list, array $attributes = []): ModListItem
{
    return ModListItem::factory()->create([
        'mod_list_id' => $list->id,
        'listable_type' => Mod::class,
        'listable_id' => $mod->id,
        ...$attributes,
    ]);
}

function renderShow(Mod $mod): Testable
{
    return Livewire::test('pages::mod.show', ['modId' => $mod->id, 'slug' => $mod->slug]);
}

/**
 * Create a publicly visible mod whose latest version has a resolved dependency on an unpublished mod. The resolved
 * rows are saved quietly to mirror production data where the dependency mod was hidden after resolution ran.
 */
function createModWithHiddenDependencyForShow(): Mod
{
    SptVersion::factory()->create(['version' => '1.0.0']);
    $mod = Mod::factory()->create();
    $modVersion = ModVersion::factory()->recycle($mod)->create([
        'version' => '2.0.0',
        'spt_version_constraint' => '1.0.0',
    ]);

    $hiddenMod = Mod::factory()->unpublished()->create(['name' => 'Hidden Dependency Mod']);
    $hiddenModVersion = ModVersion::factory()->recycle($hiddenMod)->create([
        'version' => '1.0.0',
        'spt_version_constraint' => '1.0.0',
    ]);

    $dependency = Dependency::factory()->make([
        'dependable_id' => $modVersion->id,
        'dependent_mod_id' => $hiddenMod->id,
        'constraint' => '^1.0',
    ]);
    $dependency->saveQuietly();

    DependencyResolved::factory()->make([
        'dependable_id' => $modVersion->id,
        'dependency_id' => $dependency->id,
        'resolved_mod_version_id' => $hiddenModVersion->id,
    ])->saveQuietly();

    return $mod;
}

describe('list presence summary', function (): void {
    it('omits the container when the mod is in no lists or favourites', function (): void {
        $mod = createVisibleModForShow();

        renderShow($mod)
            ->assertSuccessful()
            ->assertDontSee('This mod is featured in')
            ->assertDontSee('This mod has been favourited');
    });

    it('counts curated lists of every visibility, excluding favourites', function (): void {
        $mod = createVisibleModForShow();

        addModToList($mod, ModList::factory()->public()->create());
        addModToList($mod, ModList::factory()->hidden()->create());
        addModToList($mod, ModList::factory()->private()->create());

        renderShow($mod)
            ->assertSuccessful()
            ->assertSee('This mod is featured in 3 lists.');
    });

    it('excludes moderator-disabled lists from the count', function (): void {
        $mod = createVisibleModForShow();

        addModToList($mod, ModList::factory()->public()->create());
        addModToList($mod, ModList::factory()->public()->disabled()->create());

        renderShow($mod)
            ->assertSuccessful()
            ->assertSee('This mod is featured in 1 list.');
    });

    it('excludes tombstoned list items from the count', function (): void {
        $mod = createVisibleModForShow();

        addModToList($mod, ModList::factory()->public()->create());
        addModToList($mod, ModList::factory()->public()->create(), ['tombstoned_at' => now()]);

        renderShow($mod)
            ->assertSuccessful()
            ->assertSee('This mod is featured in 1 list.');
    });

    it('counts favourites separately from curated lists', function (): void {
        $mod = createVisibleModForShow();

        addModToList($mod, ModList::factory()->favourites()->create());
        addModToList($mod, ModList::factory()->favourites()->create());
        addModToList($mod, ModList::factory()->favourites()->create());

        renderShow($mod)
            ->assertSuccessful()
            ->assertSee('This mod has been favourited 3 times.');
    });

    it('combines and singularizes both clauses', function (): void {
        $mod = createVisibleModForShow();

        addModToList($mod, ModList::factory()->public()->create());
        addModToList($mod, ModList::factory()->favourites()->create());

        renderShow($mod)
            ->assertSuccessful()
            ->assertSee('This mod is featured in 1 list and favourited 1 time.');
    });
});

describe('dependencies on hidden mods', function (): void {
    it('renders for guests without listing a dependency whose mod is unpublished', function (): void {
        $mod = createModWithHiddenDependencyForShow();

        renderShow($mod)
            ->assertSuccessful()
            ->assertDontSee('Hidden Dependency Mod');
    });

    it('lists a dependency on an unpublished mod for administrators', function (): void {
        $mod = createModWithHiddenDependencyForShow();

        $this->actingAs(User::factory()->admin()->create());

        renderShow($mod)
            ->assertSuccessful()
            ->assertSee('Hidden Dependency Mod');
    });
});

describe('dependents tab', function (): void {
    it('labels the tab with the number of dependent mods', function (): void {
        $library = createVisibleModForShow();
        $dependent = createVisibleModForShow();
        Dependency::factory()
            ->forModVersion($dependent->versions()->firstOrFail())
            ->create(['dependent_mod_id' => $library->id]);

        renderShow($library)
            ->assertSuccessful()
            ->assertSee("selectedTab = 'dependents'", false)
            ->assertViewHas('dependentCount', 1);
    });

    it('accepts the count back as a string, as Redis returns cached numbers', function (): void {
        $mod = createVisibleModForShow();
        Cache::put(sprintf('mod:%d:dependent-count', $mod->id), '3');

        renderShow($mod)
            ->assertSuccessful()
            ->assertViewHas('dependentCount', 3);
    });

    it('omits the tab when no mod depends on this one', function (): void {
        renderShow(createVisibleModForShow())
            ->assertSuccessful()
            ->assertDontSee("selectedTab = 'dependents'", false);
    });
});

describe('last download notice', function (): void {
    it('tells a user who has the latest version that they are up to date', function (): void {
        $mod = createVisibleModForShow();
        $version = $mod->versions()->firstOrFail();
        $user = User::factory()->create();
        ModUserDownload::factory()->forVersion($version)->for($user)->create();

        $this->actingAs($user);

        renderShow($mod)
            ->assertSee('You downloaded v'.$version->version)
            ->assertDontSee('Update available');
    });

    it('tells a user on an older version that an update is available', function (): void {
        $mod = createVisibleModForShow();
        $user = User::factory()->create();
        // A pre-release of 0.0.0 sorts below any version the factory can generate.
        ModUserDownload::factory()->for($user)->create(['mod_id' => $mod->id, 'version' => '0.0.0-alpha']);

        $this->actingAs($user);

        renderShow($mod)
            ->assertSee('Update available')
            ->assertSee('You have v0.0.0-alpha');
    });

    it('shows no update notice when the recorded version cannot be compared', function (): void {
        $mod = createVisibleModForShow();
        $user = User::factory()->create();
        ModUserDownload::factory()->for($user)->create(['mod_id' => $mod->id, 'version' => 'not-a-version']);

        $this->actingAs($user);

        renderShow($mod)->assertDontSee('Update available');
    });

    it('shows nothing to a user who never downloaded the mod', function (): void {
        $this->actingAs(User::factory()->create());

        renderShow(createVisibleModForShow())
            ->assertDontSee('You downloaded')
            ->assertDontSee('Update available');
    });

    it('shows nothing to guests', function (): void {
        $mod = createVisibleModForShow();
        ModUserDownload::factory()->create(['mod_id' => $mod->id, 'version' => '0.0.1']);

        renderShow($mod)
            ->assertDontSee('You downloaded')
            ->assertDontSee('Update available');
    });
});
