<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('allows an admin to manage ownership', function (): void {
    $admin = User::factory()->admin()->create();
    $mod = Mod::factory()->create();

    expect(Gate::forUser($admin)->allows('manageOwnership', $mod))->toBeTrue();
});

it('refuses a moderator', function (): void {
    $moderator = User::factory()->moderator()->create();
    $mod = Mod::factory()->create();

    expect(Gate::forUser($moderator)->allows('manageOwnership', $mod))->toBeFalse();
});

it('refuses the mod owner', function (): void {
    $owner = User::factory()->create();
    $mod = Mod::factory()->recycle($owner)->create();

    expect(Gate::forUser($owner)->allows('manageOwnership', $mod))->toBeFalse();
});

it('refuses an admin without a verified email', function (): void {
    $admin = User::factory()->admin()->unverified()->create();
    $mod = Mod::factory()->create();

    expect(Gate::forUser($admin)->allows('manageOwnership', $mod))->toBeFalse();
});
