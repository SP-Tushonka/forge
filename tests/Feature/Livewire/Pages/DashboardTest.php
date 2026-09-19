<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModDailyStat;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

describe('dashboard', function (): void {
    it('renders the dashboard page for authenticated users', function (): void {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk();
    });

    it('redirects guests from dashboard to login', function (): void {
        $this->get('/dashboard')->assertRedirect('/login');
    });
});

it('shows an empty state to users without mods', function (): void {
    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('You have no mods yet')
        ->assertSee(route('mod.create'));
});

it('lists only the user\'s own and co-authored mods', function (): void {
    Mod::factory()->create(['owner_id' => $this->user->id, 'name' => 'Alpha Mod']);
    $coAuthored = Mod::factory()->create(['name' => 'Beta Mod']);
    $coAuthored->additionalAuthors()->attach($this->user);
    Mod::factory()->create(['name' => 'Gamma Mod']);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Alpha Mod')
        ->assertSee('Beta Mod')
        ->assertDontSee('Gamma Mod');
});

it('sorts the table by the chosen column', function (): void {
    $alpha = Mod::factory()->create(['owner_id' => $this->user->id, 'name' => 'Alpha Mod']);
    $beta = Mod::factory()->create(['owner_id' => $this->user->id, 'name' => 'Beta Mod']);
    ModDailyStat::factory()->create(['mod_id' => $alpha->id, 'date' => '2026-09-17', 'downloads' => 5, 'views' => 90]);
    ModDailyStat::factory()->create(['mod_id' => $beta->id, 'date' => '2026-09-17', 'downloads' => 50, 'views' => 10]);

    Livewire::actingAs($this->user)
        ->test('pages::dashboard')
        ->assertSeeInOrder(['Beta Mod', 'Alpha Mod'])
        ->call('sort', 'views')
        ->assertSeeInOrder(['Alpha Mod', 'Beta Mod'])
        ->call('sort', 'views')
        ->assertSeeInOrder(['Beta Mod', 'Alpha Mod']);
});

it('renders the range controls inside the component so they bind', function (): void {
    Mod::factory()->create(['owner_id' => $this->user->id]);

    Livewire::actingAs($this->user)
        ->test('pages::dashboard')
        ->assertSeeHtml('wire:model.live="days"')
        ->assertSeeHtml('wire:model.live="grain"');
});

it('ignores unknown sort columns', function (): void {
    Livewire::actingAs($this->user)
        ->test('pages::dashboard')
        ->call('sort', 'password')
        ->assertSet('sortBy', 'downloads');
});
