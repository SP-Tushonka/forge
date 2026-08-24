<?php

declare(strict_types=1);

use App\Jobs\UpdateEndorsementsJob;
use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The array cache store survives LazilyRefreshDatabase, so a section cached by one test would leak into the next.
afterEach(function (): void {
    Cache::clear();
});

beforeEach(function (): void {
    SptVersion::factory()->create(['version' => '1.0.0']);
});

/**
 * A mod with one published, enabled version, so it clears the section's visibility rules.
 *
 * @param  array<string, mixed>  $attributes
 */
function endorsableMod(array $attributes = []): Mod
{
    $mod = Mod::factory()->create($attributes);
    ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

    return $mod;
}

/**
 * Anchor the given number of standing endorsements the given number of days in the past.
 *
 * @param  array<string, mixed>  $attributes
 */
function endorseDaysAgo(Mod $mod, int $count, int $daysAgo, array $attributes = []): void
{
    test()->travel(-$daysAgo)->days();

    ModEndorsement::factory()->count($count)->create([...$attributes, 'mod_id' => $mod->id, 'endorsed_at' => now()]);

    test()->travel($daysAgo)->days();

    new UpdateEndorsementsJob()->handle();
}

/**
 * @return list<int>
 */
function endorsedModIds(Collection $mods): array
{
    return $mods->pluck('id')->map(intval(...))->values()->all();
}

describe('endorsed mods windows', function (): void {
    it('ranks the past week by endorsements anchored inside it', function (): void {
        $hot = endorsableMod();
        $warm = endorsableMod();
        $stale = endorsableMod();

        endorseDaysAgo($hot, 3, 2);
        endorseDaysAgo($warm, 2, 6);
        endorseDaysAgo($stale, 9, 9);

        Livewire::test('endorsed-mods')
            ->assertSet('window', '7d')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$hot->id, $warm->id]);
    });

    it('ranks the past month by endorsements anchored inside it', function (): void {
        $monthly = endorsableMod();
        $weekly = endorsableMod();
        $ancient = endorsableMod();

        endorseDaysAgo($monthly, 5, 20);
        endorseDaysAgo($weekly, 2, 3);
        endorseDaysAgo($ancient, 40, 45);

        Livewire::test('endorsed-mods')
            ->set('window', '30d')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$monthly->id, $weekly->id]);
    });

    it('ranks all time off the denormalised counter regardless of when the endorsements landed', function (): void {
        $ancient = endorsableMod();
        $recent = endorsableMod();

        endorseDaysAgo($ancient, 6, 400);
        endorseDaysAgo($recent, 2, 1);

        Livewire::test('endorsed-mods')
            ->set('window', 'all')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$ancient->id, $recent->id]);
    });

    it('excludes withdrawn endorsements from every window', function (): void {
        $standing = endorsableMod();
        $withdrawn = endorsableMod();

        endorseDaysAgo($standing, 1, 2);
        endorseDaysAgo($withdrawn, 5, 2, ['revoked_at' => now()]);

        foreach (['7d', '30d', 'all'] as $window) {
            Cache::clear();

            Livewire::test('endorsed-mods')
                ->set('window', $window)
                ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$standing->id]);
        }
    });
});

describe('endorsed mods visibility', function (): void {
    it('hides disabled mods, unpublished mods, and mods without a published version', function (): void {
        $visible = endorsableMod();
        $disabled = endorsableMod(['disabled' => true]);
        $unpublished = endorsableMod(['published_at' => null]);

        $hiddenVersionMod = Mod::factory()->create();
        ModVersion::factory()->recycle($hiddenVersionMod)->create(['spt_version_constraint' => '1.0.0', 'published_at' => null]);

        endorseDaysAgo($visible, 1, 2);
        endorseDaysAgo($disabled, 8, 2);
        endorseDaysAgo($unpublished, 8, 2);
        endorseDaysAgo($hiddenVersionMod, 8, 2);

        Livewire::test('endorsed-mods')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$visible->id]);
    });

    it('lets a moderator bypass the cache and see the mods a visitor cannot', function (): void {
        $visible = endorsableMod();
        $disabled = endorsableMod(['disabled' => true]);

        endorseDaysAgo($visible, 1, 2);
        endorseDaysAgo($disabled, 8, 2);

        Livewire::test('endorsed-mods')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$visible->id]);

        expect(Cache::has('homepage:sections:endorsed:7d'))->toBeTrue();

        Livewire::actingAs(User::factory()->moderator()->create())
            ->test('endorsed-mods')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$disabled->id, $visible->id]);
    });
});

describe('endorsed mods window parameter', function (): void {
    it('falls back to the default window when the url parameter is junk', function (): void {
        Livewire::withQueryParams(['endorsed' => 'last-tuesday'])
            ->test('endorsed-mods')
            ->assertSet('window', '7d');
    });

    it('falls back to the default window when the property is set to junk', function (): void {
        Livewire::test('endorsed-mods')
            ->set('window', '90d')
            ->assertSet('window', '7d');
    });

    it('keeps a valid window', function (): void {
        Livewire::withQueryParams(['endorsed' => 'all'])
            ->test('endorsed-mods')
            ->assertSet('window', 'all');
    });
});

describe('endorsed mods count coherence', function (): void {
    it('displays the cached counts that produced the ordering, not the live counter', function (): void {
        $first = endorsableMod();
        $second = endorsableMod();
        $third = endorsableMod();

        endorseDaysAgo($first, 3, 400);
        endorseDaysAgo($second, 2, 400);
        endorseDaysAgo($third, 1, 400);

        Livewire::test('endorsed-mods')
            ->set('window', 'all')
            ->assertViewHas('endorsementCounts', fn (array $counts): bool => $counts === [$first->id => 3, $second->id => 2, $third->id => 1]);

        // Endorsing moves the counter through the query builder, firing no model events, so the section cache stands.
        DB::table('mods')->where('id', $third->id)->update(['endorsements_count' => 500]);

        Livewire::test('endorsed-mods')
            ->set('window', 'all')
            ->assertViewHas('mods', fn (Collection $mods): bool => endorsedModIds($mods) === [$first->id, $second->id, $third->id])
            ->assertViewHas('endorsementCounts', fn (array $counts): bool => $counts[$third->id] === 1);
    });

    it('keeps the card ordering and the displayed counts in agreement', function (): void {
        $mods = [];

        foreach ([9, 4, 7, 1] as $index => $count) {
            $mods[$count] = endorsableMod();
            endorseDaysAgo($mods[$count], $count, $index + 1);
        }

        Livewire::test('endorsed-mods')
            ->assertViewHas('mods', fn (Collection $rendered): bool => endorsedModIds($rendered) === [$mods[9]->id, $mods[7]->id, $mods[4]->id, $mods[1]->id])
            ->assertViewHas('endorsementCounts', fn (array $counts): bool => array_values($counts) === [9, 7, 4, 1]);
    });
});

describe('moderation from the endorsed section', function (): void {
    // The mod action dropdown on every card calls $wire.$parent.deleteMod, so whichever component hosts the card
    // has to expose it. Testing the section in isolation cannot catch a missing $parent method.
    it('allows an administrator to delete a mod from the section', function (): void {
        $mod = endorsableMod();
        endorseDaysAgo($mod, 1, 1);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('endorsed-mods')
            ->call('deleteMod', $mod->id);

        expect(Mod::query()->find($mod->id))->toBeNull();
    });

    it('prevents a normal user from deleting a mod from the section', function (): void {
        $mod = endorsableMod();
        endorseDaysAgo($mod, 1, 1);

        Livewire::actingAs(User::factory()->create())
            ->test('endorsed-mods')
            ->call('deleteMod', $mod->id)
            ->assertForbidden();

        expect(Mod::query()->find($mod->id))->not->toBeNull();
    });
});

describe('the section on the homepage', function (): void {
    it('renders inside the homepage with its endorsed mods', function (): void {
        $mod = endorsableMod();
        endorseDaysAgo($mod, 2, 1);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Most Endorsed Mods')
            ->assertSee($mod->name);
    });
});
