<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->owner = User::factory()->create();
    $this->mod = Mod::factory()->create(['owner_id' => $this->owner->id, 'published_at' => now()->subMonth()]);
    $this->url = route('mod.stats', ['modId' => $this->mod->id, 'slug' => $this->mod->slug]);
});

it('shows the stats page to the owner', function (): void {
    $this->actingAs($this->owner)->get($this->url)
        ->assertOk()
        ->assertSee('Downloads per version')
        ->assertSee('Top countries (downloads)');
});

it('shows the stats page to co-authors and staff administrators', function (): void {
    $author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($author);

    $this->actingAs($author)->get($this->url)->assertOk();
    $this->actingAs(User::factory()->admin()->create())->get($this->url)->assertOk();
});

it('forbids moderators and unrelated users', function (): void {
    $this->actingAs(User::factory()->moderator()->create())->get($this->url)->assertForbidden();
    $this->actingAs(User::factory()->create())->get($this->url)->assertForbidden();
});

it('sends guests to the login page', function (): void {
    $this->get($this->url)->assertRedirect(route('login'));
});

it('still shows stats for the owner\'s unpublished and disabled mods', function (): void {
    $unpublished = Mod::factory()->unpublished()->create(['owner_id' => $this->owner->id]);
    $disabled = Mod::factory()->disabled()->create(['owner_id' => $this->owner->id]);

    $this->actingAs($this->owner)->get(route('mod.stats', ['modId' => $unpublished->id, 'slug' => $unpublished->slug]))->assertOk();
    $this->actingAs($this->owner)->get(route('mod.stats', ['modId' => $disabled->id, 'slug' => $disabled->slug]))->assertOk();
});

it('redirects to the canonical slug', function (): void {
    $this->actingAs($this->owner)
        ->get(route('mod.stats', ['modId' => $this->mod->id, 'slug' => 'wrong-slug']))
        ->assertRedirect($this->url);
});

it('never names a country below the threshold', function (): void {
    ModCountryDailyDownload::factory()->create(['mod_id' => $this->mod->id, 'date' => '2026-09-17', 'country_code' => 'DE', 'downloads' => 50]);
    ModCountryDailyDownload::factory()->create(['mod_id' => $this->mod->id, 'date' => '2026-09-17', 'country_code' => 'IS', 'downloads' => 3]);

    $this->actingAs($this->owner)->get($this->url)
        ->assertSee('Germany')
        ->assertSee('Other (1 country)')
        ->assertDontSee('Iceland');
});

it('reads the range from the URL and keeps a 7-day range daily', function (): void {
    Livewire::withQueryParams(['days' => '90', 'grain' => 'weekly'])
        ->actingAs($this->owner)
        ->test('pages::mod.stats', ['modId' => $this->mod->id, 'slug' => $this->mod->slug])
        ->assertSet('days', '90')
        ->assertSet('grain', 'weekly')
        ->set('days', '7')
        ->assertSet('grain', 'daily');
});

it('renders the range controls inside the component so they bind', function (): void {
    Livewire::actingAs($this->owner)
        ->test('pages::mod.stats', ['modId' => $this->mod->id, 'slug' => $this->mod->slug])
        ->assertSeeHtml('wire:model.live="days"')
        ->assertSeeHtml('wire:model.live="grain"');
});

it('explains the flat start of the chart before collection began', function (): void {
    $this->actingAs($this->owner)->get($this->url)
        ->assertSee('Days before that show as 0 because nothing was collected yet');
});

it('shows an empty state when there are no downloads', function (): void {
    $this->actingAs($this->owner)->get($this->url)->assertSee('No downloads in this period.');
});
