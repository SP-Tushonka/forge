<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Comment;
use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\ModIssue;
use App\Models\ModList;
use App\Models\ModListItem;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/*
 * A viewer ignores one mod and one user. Every surface that lists mods, addons or lists must drop the ignored mod and
 * everything the ignored user owns, and still show the control mod. Add new surfaces here.
 */

function ignoreCoverageMod(array $attributes): Mod
{
    $mod = Mod::factory()->create(['published_at' => now()->subDay(), 'created_at' => now()->subDay(), ...$attributes]);
    ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0', 'created_at' => now()->subDay()]);

    return $mod;
}

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::factory()->create(['version' => '1.0.0']);

    $this->viewer = User::factory()->create();
    $this->ignoredUser = User::factory()->create();

    $this->control = ignoreCoverageMod(['name' => 'Control Mod']);
    $this->ignoredMod = ignoreCoverageMod(['name' => 'Ignored Mod']);
    $this->authoredMod = ignoreCoverageMod(['name' => 'Authored Mod', 'owner_id' => $this->ignoredUser->id]);

    $this->viewer->ignoreMod($this->ignoredMod);
    $this->viewer->ignoreUser($this->ignoredUser);
});

afterEach(function (): void {
    Cache::clear();
});

describe('query-scoped surfaces', function (): void {
    it('filters the browse page for the viewer only', function (): void {
        Livewire::actingAs($this->viewer)->test('pages::mod.index')
            ->assertSee('Control Mod')
            ->assertDontSee('Ignored Mod')
            ->assertDontSee('Authored Mod');

        Livewire::actingAs(User::factory()->create())->test('pages::mod.index')
            ->assertSee('Ignored Mod')
            ->assertSee('Authored Mod');
    });

    it('never serves the shared browse total to a viewer with ignores', function (): void {
        $member = User::factory()->create();

        Livewire::actingAs($member)->test('pages::mod.index')->assertViewHas('mods', fn ($mods) => $mods->total() === 3);

        ignoreCoverageMod(['name' => 'Late Mod']);

        // The member still gets the cached 3, proving the cache is warm for these filters.
        Livewire::actingAs($member)->test('pages::mod.index')->assertViewHas('mods', fn ($mods) => $mods->total() === 3);
        Livewire::actingAs($this->viewer)->test('pages::mod.index')->assertViewHas('mods', fn ($mods) => $mods->total() === 2);
    });

    it('leaves the RSS feed unfiltered', function (): void {
        $this->actingAs($this->viewer)->get(route('mods.rss'))
            ->assertSee('Ignored Mod')
            ->assertSee('Authored Mod');
    });

    it('filters recently updated and recently created', function (string $page): void {
        Livewire::actingAs($this->viewer)->test($page)
            ->assertSee('Control Mod')
            ->assertDontSee('Ignored Mod')
            ->assertDontSee('Authored Mod');
    })->with(['pages::mod.recently-updated', 'pages::mod.recently-created']);

    it('leaves ignored mods out of the nav badge counts', function (): void {
        $this->viewer->forceFill([
            'mods_created_viewed_at' => now()->subWeek(),
            'mods_updated_viewed_at' => now()->subWeek(),
        ])->save();

        Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('navigation-created-mods-badge')->assertSet('createdCount', 1);
        Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('navigation-updated-mods-badge')->assertSet('updatedCount', 1);
    });

    it('filters the lists index', function (): void {
        ModList::factory()->public()->create(['owner_id' => $this->ignoredUser->id, 'title' => 'Ignored List']);
        ModList::factory()->public()->create(['title' => 'Control List']);

        Livewire::actingAs($this->viewer)->test('pages::list.index')
            ->assertSee('Control List')
            ->assertDontSee('Ignored List');
    });

    it("filters an ignored user's profile tabs", function (): void {
        $coauthored = ignoreCoverageMod(['name' => 'Coauthored Mod']);
        $coauthored->additionalAuthors()->attach($this->ignoredUser);
        ModList::factory()->public()->create(['owner_id' => $this->ignoredUser->id, 'title' => 'Ignored List']);

        Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('user.show.mods-tab', ['userId' => $this->ignoredUser->id])
            ->assertDontSee('Authored Mod')
            ->assertSee('Coauthored Mod');

        Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('user.show.lists-tab', ['userId' => $this->ignoredUser->id])
            ->assertDontSee('Ignored List');
    });

    it("filters a mod's addons tab", function (): void {
        $this->control->update(['addons_disabled' => false]);

        foreach (['Ignored Addon' => $this->ignoredUser->id, 'Control Addon' => User::factory()->create()->id] as $name => $ownerId) {
            $addon = Addon::factory()->create([
                'mod_id' => $this->control->id,
                'owner_id' => $ownerId,
                'name' => $name,
                'published_at' => now()->subDay(),
                'disabled' => false,
            ]);
            AddonVersion::factory()->recycle($addon)->create([
                'mod_version_constraint' => '^1.0.0',
                'published_at' => now()->subDay(),
                'disabled' => false,
            ]);
        }

        Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('mod.show.addons-tab', ['modId' => $this->control->id])
            ->assertSee('Control Addon')
            ->assertDontSee('Ignored Addon');
    });

    it("counts a mod's addons the way its addons tab lists them, also on a mod shown anyway", function (): void {
        foreach ([$this->control, $this->ignoredMod] as $mod) {
            $mod->update(['addons_disabled' => false]);

            foreach ([$this->ignoredUser->id, User::factory()->create()->id] as $ownerId) {
                $addon = Addon::factory()->create([
                    'mod_id' => $mod->id,
                    'owner_id' => $ownerId,
                    'published_at' => now()->subDay(),
                    'disabled' => false,
                ]);
                AddonVersion::factory()->recycle($addon)->create([
                    'mod_version_constraint' => '^1.0.0',
                    'published_at' => now()->subDay(),
                    'disabled' => false,
                ]);
            }
        }

        foreach ([[$this->control, 1], [$this->ignoredMod, 2]] as [$mod, $visible]) {
            Livewire::actingAs($this->viewer)->test('pages::mod.show', ['modId' => $mod->id, 'slug' => $mod->slug])
                ->assertViewHas('addonCount', $visible);

            $tab = Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('mod.show.addons-tab', ['modId' => $mod->id])->instance();

            expect($tab->addonCount)->toBe($visible)
                ->and($tab->addons->total())->toBe($visible);
        }
    });

    it("filters a mod's dependents tab", function (): void {
        $visibleDependent = ignoreCoverageMod(['name' => 'Visible Dependent']);

        foreach ([$this->ignoredMod, $this->authoredMod, $visibleDependent] as $dependent) {
            $version = $dependent->versions()->firstOrFail();
            $version->update(['published_at' => now()->subDay(), 'disabled' => false]);
            Dependency::factory()->forModVersion($version)->create(['dependent_mod_id' => $this->control->id]);
        }

        Livewire::withoutLazyLoading()->actingAs($this->viewer)->test('mod.show.dependents-tab', ['modId' => $this->control->id])
            ->assertSee('Visible Dependent')
            ->assertDontSee('Ignored Mod')
            ->assertDontSee('Authored Mod');
    });
});

describe('cached and post-filtered surfaces', function (): void {
    it('filters the homepage sections for the viewer while the shared cache stays complete', function (): void {
        Mod::query()->update(['featured' => true]);

        Livewire::actingAs($this->viewer)->test('pages::homepage')
            ->assertViewHas('newest', fn ($mods) => $mods->pluck('id')->doesntContain($this->ignoredMod->id)
                && $mods->pluck('id')->doesntContain($this->authoredMod->id)
                && $mods->pluck('id')->contains($this->control->id))
            ->assertViewHas('featured', fn ($mods) => $mods->pluck('id')->doesntContain($this->ignoredMod->id)
                && $mods->pluck('id')->contains($this->control->id));

        Livewire::actingAs(User::factory()->create())->test('pages::homepage')
            ->assertViewHas('newest', fn ($mods) => $mods->pluck('id')->contains($this->ignoredMod->id));
    });

    it('never shows disabled mods to a member on the live homepage path', function (): void {
        $this->control->update(['disabled' => true]);

        Livewire::actingAs($this->viewer)->test('pages::homepage')
            ->assertViewHas('newest', fn ($mods) => $mods->pluck('id')->doesntContain($this->control->id));
    });

    it('drops recent comments on ignored mods from the homepage feed', function (): void {
        Comment::factory()->recycle($this->ignoredMod)->withVersion()->create();
        Comment::factory()->recycle($this->control)->withVersion()->create();

        Livewire::actingAs($this->viewer)->test('pages::homepage')
            ->assertViewHas('recentComments', fn ($comments) => $comments->pluck('commentable_id')->doesntContain($this->ignoredMod->id)
                && $comments->pluck('commentable_id')->contains($this->control->id));
    });

    it('filters the endorsed leaderboard', function (): void {
        foreach ([$this->ignoredMod, $this->authoredMod, $this->control] as $mod) {
            ModEndorsement::factory()->create(['mod_id' => $mod->id, 'endorsed_at' => now()]);
        }

        Livewire::actingAs($this->viewer)->test('endorsed-mods')
            ->assertViewHas('mods', fn ($mods) => $mods->pluck('id')->doesntContain($this->ignoredMod->id)
                && $mods->pluck('id')->doesntContain($this->authoredMod->id)
                && $mods->pluck('id')->contains($this->control->id));

        Livewire::actingAs(User::factory()->create())->test('endorsed-mods')
            ->assertViewHas('mods', fn ($mods) => $mods->pluck('id')->contains($this->ignoredMod->id));
    });

    it('filters global search results', function (): void {
        Livewire::actingAs($this->viewer)->test('global-search')
            ->set('query', 'Mod')
            ->assertSee('Control Mod')
            ->assertDontSee('Ignored Mod')
            ->assertDontSee('Authored Mod');
    });

    it("filters an ignored mod out of someone's list for the viewer, but not for the list's owner", function (): void {
        $owner = User::factory()->create();
        $list = ModList::factory()->public()->create(['owner_id' => $owner->id]);

        foreach ([$this->ignoredMod, $this->control] as $mod) {
            ModListItem::factory()->create([
                'mod_list_id' => $list->id,
                'listable_type' => Mod::class,
                'listable_id' => $mod->id,
            ]);
        }

        // An addon on the list without its parent mod, by an ignored author: the viewer loses the whole group.
        $orphanParent = ignoreCoverageMod(['name' => 'Orphan Parent']);
        $orphanAddon = Addon::factory()->create([
            'mod_id' => $orphanParent->id,
            'owner_id' => $this->ignoredUser->id,
            'published_at' => now()->subDay(),
        ]);
        ModListItem::factory()->create([
            'mod_list_id' => $list->id,
            'listable_type' => Addon::class,
            'listable_id' => $orphanAddon->id,
        ]);

        $owner->ignoreMod($this->ignoredMod);

        Livewire::actingAs($this->viewer)->test('pages::list.show', ['listId' => $list->id, 'slug' => $list->slug])
            ->assertViewHas('grouped', fn ($groups) => $groups->pluck('mod.id')->doesntContain($this->ignoredMod->id)
                && $groups->pluck('mod.id')->doesntContain($orphanParent->id)
                && $groups->pluck('mod.id')->contains($this->control->id));

        Livewire::actingAs($owner)->test('pages::list.show', ['listId' => $list->id, 'slug' => $list->slug])
            ->assertViewHas('grouped', fn ($groups) => $groups->firstWhere('mod.id', $this->ignoredMod->id)['ignored'] === true
                && $groups->pluck('mod.id')->contains($orphanParent->id));
    });

    it('drops activity about ignored mods from a profile', function (): void {
        $actor = User::factory()->create();

        foreach ([$this->ignoredMod, $this->control] as $mod) {
            TrackingEvent::factory()->create([
                'visitor_id' => $actor->id,
                'visitor_type' => User::class,
                'visitable_type' => Mod::class,
                'visitable_id' => $mod->id,
                'event_name' => TrackingEventType::MOD_REACT->value,
            ]);
        }

        $activity = Livewire::actingAs($this->viewer)->test('user-activity', ['user' => $actor])->instance()->recentActivity;

        expect($activity->pluck('visitable_id')->all())->toBe([$this->control->id]);
    });

    it('drops issue activity on ignored mods from a profile', function (): void {
        $actor = User::factory()->create();

        foreach ([$this->ignoredMod, $this->control] as $mod) {
            $issue = ModIssue::factory()->create(['mod_id' => $mod->id]);

            TrackingEvent::factory()->create([
                'visitor_id' => $actor->id,
                'visitor_type' => User::class,
                'visitable_type' => ModIssue::class,
                'visitable_id' => $issue->id,
                'event_name' => TrackingEventType::ISSUE_CREATE->value,
            ]);
        }

        $activity = Livewire::actingAs($this->viewer)->test('user-activity', ['user' => $actor])->instance()->recentActivity;

        expect($activity->map(fn (TrackingEvent $event) => $event->visitable->mod_id)->all())->toBe([$this->control->id]);
    });
});
