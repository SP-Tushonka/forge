<?php

declare(strict_types=1);

use App\Models\User;

it('renders for staff', function (): void {
    $staff = User::factory()->admin()->create();

    $this->actingAs($staff)
        ->get(route('admin.staff-tools'))
        ->assertOk()
        ->assertSee('Staff Tools');
});

it('forbids moderators', function (): void {
    $this->actingAs(User::factory()->moderator()->create())
        ->get(route('admin.staff-tools'))
        ->assertForbidden();
});

it('forbids regular users', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('admin.staff-tools'))
        ->assertForbidden();
});

it('redirects guests', function (): void {
    $this->get(route('admin.staff-tools'))->assertRedirect();
});
