<?php

declare(strict_types=1);

use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModList;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());

    $this->author = User::factory()->create(['name' => 'Ignored Author']);
    $this->mod = Mod::factory()->create([
        'name' => 'Ignored Mod',
        'owner_id' => $this->author->id,
        'published_at' => now()->subHour(),
        'issues_enabled' => true,
    ]);
    ModVersion::factory()->recycle($this->mod)->create(['spt_version_constraint' => '1.0.0']);

    $this->viewer = User::factory()->create();
});

$notice = 'data-test="ignored-content-notice"';

describe('mod page', function () use ($notice): void {
    it('shows the notice instead of an ignored mod', function () use ($notice): void {
        $this->viewer->ignoreMod($this->mod);

        $this->actingAs($this->viewer)->get($this->mod->detail_url)
            ->assertOk()
            ->assertSee($notice, false)
            ->assertSee("You're ignoring Ignored Mod.")
            ->assertDontSee('data-test="mod-subscribe-button"', false);
    });

    it('names the ignored owner', function (): void {
        $this->viewer->ignoreUser($this->author);

        $this->actingAs($this->viewer)->get($this->mod->detail_url)
            ->assertSee("You're ignoring content from Ignored Author.");
    });

    it('reloads the page to show it anyway on request', function (): void {
        $this->viewer->ignoreMod($this->mod);

        Livewire::actingAs($this->viewer)
            ->test('pages::mod.show', ['modId' => $this->mod->id, 'slug' => $this->mod->slug])
            ->call('showIgnoredContent')
            ->assertRedirect($this->mod->detail_url.'?show-ignored=1');
    });

    it('renders the body and the header actions when shown anyway, keeping the ignore', function () use ($notice): void {
        $this->viewer->ignoreMod($this->mod);

        $this->actingAs($this->viewer)->get($this->mod->detail_url.'?show-ignored=1')
            ->assertOk()
            ->assertDontSee($notice, false)
            ->assertSee('data-test="mod-subscribe-button"', false)
            ->assertSee('data-test="ignore-mod-button"', false);

        expect($this->viewer->ignoredMods()->exists())->toBeTrue();
    });

    it('stops ignoring the mod and reloads, which shows the next reason', function () use ($notice): void {
        $this->viewer->ignoreUser($this->author);
        $this->viewer->ignoreMod($this->mod);
        $page = fn () => Livewire::actingAs($this->viewer)
            ->test('pages::mod.show', ['modId' => $this->mod->id, 'slug' => $this->mod->slug]);

        $page()->assertSee("You're ignoring Ignored Mod.")
            ->call('stopIgnoring')
            ->assertRedirect($this->mod->detail_url);

        expect($this->viewer->ignoredMods()->exists())->toBeFalse();
        $this->actingAs($this->viewer)->get($this->mod->detail_url)
            ->assertSee("You're ignoring content from Ignored Author.");

        $page()->call('stopIgnoring')->assertRedirect($this->mod->detail_url);

        expect($this->viewer->ignoredUsers()->exists())->toBeFalse();
        $this->actingAs($this->viewer)->get($this->mod->detail_url)
            ->assertDontSee($notice, false)
            ->assertSee('data-test="mod-subscribe-button"', false);
    });

    it('does not affect other viewers', function () use ($notice): void {
        $this->viewer->ignoreMod($this->mod);

        $this->actingAs(User::factory()->create())->get($this->mod->detail_url)->assertDontSee($notice, false);
    });
});

it('shows the notice on an addon of an ignored mod', function () use ($notice): void {
    $addon = Addon::factory()->create(['mod_id' => $this->mod->id, 'published_at' => now()->subHour()]);
    AddonVersion::factory()->recycle($addon)->create(['published_at' => now()->subHour()]);
    $this->viewer->ignoreMod($this->mod);

    $this->actingAs($this->viewer)
        ->get(route('addon.show', ['addonId' => $addon->id, 'slug' => $addon->slug]))
        ->assertOk()
        ->assertSee($notice, false);
});

it('shows the notice on a list owned by an ignored user', function () use ($notice): void {
    $list = ModList::factory()->public()->create(['owner_id' => $this->author->id]);
    $this->viewer->ignoreUser($this->author);

    $this->actingAs($this->viewer)
        ->get(route('list.show', ['listId' => $list->id, 'slug' => $list->slug]))
        ->assertOk()
        ->assertSee($notice, false);
});

it('shows the notice on an issue of an ignored mod', function () use ($notice): void {
    $issue = ModIssue::factory()->for($this->mod)->create();
    $this->viewer->ignoreMod($this->mod);

    $this->actingAs($this->viewer)
        ->get(route('mod.issue.show', ['modId' => $this->mod->id, 'slug' => $this->mod->slug, 'number' => $issue->number]))
        ->assertOk()
        ->assertSee($notice, false);
});

describe('reloading the other pages', function () use ($notice): void {
    it('reloads an addon at its own url, shown with its header', function () use ($notice): void {
        $addon = Addon::factory()->create(['mod_id' => $this->mod->id, 'published_at' => now()->subHour()]);
        AddonVersion::factory()->recycle($addon)->create(['published_at' => now()->subHour()]);
        $this->viewer->ignoreMod($this->mod);

        Livewire::actingAs($this->viewer)
            ->test('pages::addon.show', ['addonId' => $addon->id, 'slug' => $addon->slug])
            ->call('showIgnoredContent')
            ->assertRedirect($addon->detail_url.'?show-ignored=1');

        $this->actingAs($this->viewer)->get($addon->detail_url.'?show-ignored=1')
            ->assertOk()
            ->assertDontSee($notice, false)
            ->assertSee('Add to list');
    });

    it('reloads a list at the share link it was opened with', function () use ($notice): void {
        $list = ModList::factory()->hidden()->create(['owner_id' => $this->author->id]);
        $sharedUrl = route('list.show.shared', ['listId' => $list->id, 'slug' => $list->slug, 'shareToken' => $list->share_token]);
        $this->viewer->ignoreUser($this->author);

        Livewire::withQueryParams(['share_token' => $list->share_token])
            ->actingAs($this->viewer)
            ->test('pages::list.show', ['listId' => $list->id, 'slug' => $list->slug, 'shareToken' => $list->share_token])
            ->call('showIgnoredContent')
            ->assertRedirect($sharedUrl.'?show-ignored=1');

        $this->actingAs($this->viewer)->get($sharedUrl.'?show-ignored=1')
            ->assertOk()
            ->assertDontSee($notice, false);
    });

    it('reloads a public list at its own url after stopping ignoring its owner', function (): void {
        $list = ModList::factory()->public()->create(['owner_id' => $this->author->id]);
        $this->viewer->ignoreUser($this->author);

        Livewire::actingAs($this->viewer)
            ->test('pages::list.show', ['listId' => $list->id, 'slug' => $list->slug])
            ->call('stopIgnoring')
            ->assertRedirect($list->detailUrl());

        expect($this->viewer->ignoredUsers()->exists())->toBeFalse();
    });

    it('reloads an issue at its own url', function () use ($notice): void {
        $issue = ModIssue::factory()->for($this->mod)->create();
        $this->viewer->ignoreMod($this->mod);

        Livewire::actingAs($this->viewer)
            ->test('pages::mod-issue.show', ['modId' => $this->mod->id, 'slug' => $this->mod->slug, 'number' => $issue->number])
            ->call('showIgnoredContent')
            ->assertRedirect($issue->url().'?show-ignored=1');

        $this->actingAs($this->viewer)->get($issue->url().'?show-ignored=1')
            ->assertOk()
            ->assertDontSee($notice, false);
    });
});
