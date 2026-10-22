<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('lists ignored users with their scope and stops ignoring one', function (): void {
    $user = User::factory()->create();
    $owned = User::factory()->create(['name' => 'Owned Person']);
    $wide = User::factory()->create(['name' => 'Wide Person']);
    $user->ignoreUser($owned);
    $user->ignoreUser($wide, includeCoauthored: true);

    Livewire::actingAs($user)
        ->test('ignored-users')
        ->assertSee('Owned Person')
        ->assertSee('Owned only')
        ->assertSee('Wide Person')
        ->assertSee('Owned and co-authored')
        ->call('stopIgnoring', $owned->id)
        ->assertDontSee('Owned Person');

    expect($user->ignoredUsers()->pluck('ignored_user_id')->all())->toBe([$wide->id]);
});

it('shows an empty state', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test('ignored-users')
        ->assertSee("You haven't ignored any users.");
});
