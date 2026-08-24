<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\User;
use Illuminate\Database\QueryException;

it('allows only one endorsement row per user and mod', function (): void {
    $user = User::factory()->create();
    $mod = Mod::factory()->create();

    ModEndorsement::factory()->for($user)->for($mod)->create();

    expect(fn (): ModEndorsement => ModEndorsement::factory()->for($user)->for($mod)->create())
        ->toThrow(QueryException::class);
});

it('allows the same user to endorse different mods', function (): void {
    $user = User::factory()->create();

    ModEndorsement::factory()->for($user)->for(Mod::factory())->create();
    ModEndorsement::factory()->for($user)->for(Mod::factory())->create();

    expect(ModEndorsement::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('scopes to endorsements that currently stand', function (): void {
    $mod = Mod::factory()->create();

    ModEndorsement::factory()->count(3)->for($mod)->create();
    ModEndorsement::factory()->count(2)->for($mod)->revoked()->create();

    expect(ModEndorsement::query()->count())->toBe(5)
        ->and(ModEndorsement::query()->active()->count())->toBe(3)
        ->and($mod->endorsements()->count())->toBe(5);
});

it('keeps the anchor on a withdrawn endorsement', function (): void {
    $endorsement = ModEndorsement::factory()->revoked()->create();

    expect($endorsement->revoked_at)->not->toBeNull()
        ->and($endorsement->endorsed_at)->not->toBeNull();
});

it('deletes the endorsement when the user is deleted', function (): void {
    $user = User::factory()->create();
    $endorsement = ModEndorsement::factory()->for($user)->create();

    $user->delete();

    expect(ModEndorsement::query()->whereKey($endorsement->id)->exists())->toBeFalse();
});

it('deletes the endorsement when the mod is deleted', function (): void {
    $mod = Mod::factory()->create();
    $endorsement = ModEndorsement::factory()->for($mod)->create();

    $mod->delete();

    expect(ModEndorsement::query()->whereKey($endorsement->id)->exists())->toBeFalse();
});

it('resolves both sides of the relationship', function (): void {
    $user = User::factory()->create();
    $mod = Mod::factory()->create();

    $endorsement = ModEndorsement::factory()->for($user)->for($mod)->create();

    expect($endorsement->user->id)->toBe($user->id)
        ->and($endorsement->mod->id)->toBe($mod->id)
        ->and($user->modEndorsements()->count())->toBe(1)
        ->and($mod->endorsements()->count())->toBe(1);
});
