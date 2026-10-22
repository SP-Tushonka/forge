<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\User;
use Livewire\Livewire;

it('lists ignored mods and stops ignoring one', function (): void {
    $user = User::factory()->create();
    $mod = Mod::factory()->create(['name' => 'Noisy Mod']);
    $user->ignoreMod($mod);

    Livewire::actingAs($user)
        ->test('ignored-mods')
        ->assertSee('Noisy Mod')
        ->call('stopIgnoring', $mod->id)
        ->assertDontSee('Noisy Mod');

    expect($user->ignoredMods()->exists())->toBeFalse();
});

it('keeps unpublished ignored mods in the list', function (): void {
    $user = User::factory()->create();
    $mod = Mod::factory()->create(['name' => 'Pulled Mod', 'published_at' => null]);
    $user->ignoreMod($mod);

    Livewire::actingAs($user)->test('ignored-mods')->assertSee('Pulled Mod');
});

it('shows an empty state', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('ignored-mods')
        ->assertSee("You haven't ignored any mods.");
});

it('is on the profile edit page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('profile.show'))
        ->assertSee('Ignored Mods')
        ->assertSee('Ignored Users');
});
