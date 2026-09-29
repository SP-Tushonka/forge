<?php

declare(strict_types=1);

use App\Models\User;

describe('user menu', function (): void {
    it('offers Create New Mod to users who may create mods', function (): void {
        $this->actingAs(User::factory()->withMfa()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Create New Mod')
            ->assertSee(route('mod.guidelines'), false);
    });

    it('hides Create New Mod from users without MFA', function (): void {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('Create New Mod');
    });
});
