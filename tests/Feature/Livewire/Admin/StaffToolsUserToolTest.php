<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

it('finds a user by name', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Tushonka']);

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool')
        ->set('search', 'Tushonka')
        ->assertSee('Tushonka')
        ->assertSee((string) $target->id);
});

it('finds a user by email', function (): void {
    $staff = User::factory()->admin()->create();
    User::factory()->create(['name' => 'Vasily', 'email' => 'vasily@example.com']);

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool')
        ->set('search', 'vasily@example.com')
        ->assertSee('Vasily');
});

it('finds a user by id', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Chomp']);

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool')
        ->set('search', (string) $target->id)
        ->assertSee('Chomp');
});

it('finds a user who has blocked the staff member', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Blocker']);
    $target->blocking()->create(['blocked_id' => $staff->id]);

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool')
        ->set('search', 'Blocker')
        ->assertSee('Blocker');
});

it('resolves a target from the url parameter', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['name' => 'Deeplinked']);

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->assertSee('Deeplinked');
});

it('forbids non staff', function (): void {
    Livewire::actingAs(User::factory()->moderator()->create())
        ->test('admin.staff-tools.user-tool')
        ->assertForbidden();
});
