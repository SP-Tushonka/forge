<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->mod = Mod::factory()->create(['owner_id' => $this->owner->id, 'published_at' => now()->subDay()]);
    $this->menu = fn (User $user) => Livewire::actingAs($user)
        ->test('mod.action', [
            'modId' => $this->mod->id,
            'modName' => $this->mod->name,
            'modFeatured' => false,
            'modDisabled' => false,
            'modPublished' => true,
        ])
        ->call('loadMenu');
    $this->statsUrl = route('mod.stats', ['modId' => $this->mod->id, 'slug' => $this->mod->slug]);
});

it('shows the stats link to the owner', function (): void {
    ($this->menu)($this->owner)->assertSee($this->statsUrl);
});

it('shows the stats link to staff administrators', function (): void {
    ($this->menu)(User::factory()->admin()->create())->assertSee($this->statsUrl);
});

it('hides the stats link from moderators', function (): void {
    ($this->menu)(User::factory()->moderator()->create())->assertDontSee($this->statsUrl);
});
