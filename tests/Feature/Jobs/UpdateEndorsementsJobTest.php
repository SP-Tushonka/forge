<?php

declare(strict_types=1);

use App\Jobs\UpdateEndorsementsJob;
use App\Models\Mod;
use App\Models\ModEndorsement;

it('repairs a drifted endorsement count', function (): void {
    $modOverstated = Mod::factory()->create(['endorsements_count' => 99]);

    $modEndorsedOnce = Mod::factory()->create(['endorsements_count' => 0]);
    ModEndorsement::factory()->for($modEndorsedOnce)->create();

    $modEndorsedTwice = Mod::factory()->create(['endorsements_count' => 7]);
    ModEndorsement::factory()->count(2)->for($modEndorsedTwice)->create();

    (new UpdateEndorsementsJob)->handle();

    expect($modOverstated->refresh()->endorsements_count)->toBe(0)
        ->and($modEndorsedOnce->refresh()->endorsements_count)->toBe(1)
        ->and($modEndorsedTwice->refresh()->endorsements_count)->toBe(2);
});

it('leaves an already correct count untouched', function (): void {
    $mod = Mod::factory()->create(['endorsements_count' => 2, 'updated_at' => now()->subDays(10)]);
    ModEndorsement::factory()->count(2)->for($mod)->create();

    $originalUpdatedAt = $mod->updated_at;

    (new UpdateEndorsementsJob)->handle();

    $mod->refresh();

    expect($mod->endorsements_count)->toBe(2)
        ->and($mod->updated_at?->toIso8601String())->toBe($originalUpdatedAt?->toIso8601String());
});

it('ignores withdrawn endorsements', function (): void {
    $mod = Mod::factory()->create(['endorsements_count' => 0]);

    ModEndorsement::factory()->for($mod)->create();
    ModEndorsement::factory()->count(3)->for($mod)->revoked()->create();

    (new UpdateEndorsementsJob)->handle();

    expect($mod->refresh()->endorsements_count)->toBe(1);
});

it('repairs unpublished and future dated mods too', function (): void {
    // Nothing is authenticated inside a queued job, so PublishedScope hides both of these from an unscoped
    // Mod::query(). Without withoutGlobalScope their counters would drift forever, and the mod detail page - which
    // the owner can see - would keep showing the wrong total.
    $unpublished = Mod::factory()->unpublished()->create(['endorsements_count' => 42]);
    ModEndorsement::factory()->for($unpublished)->create();

    $futureDated = Mod::factory()->create(['published_at' => now()->addWeek(), 'endorsements_count' => 0]);
    ModEndorsement::factory()->count(2)->for($futureDated)->create();

    expect(Mod::query()->whereKey([$unpublished->id, $futureDated->id])->count())->toBe(0);

    (new UpdateEndorsementsJob)->handle();

    expect($unpublished->refresh()->endorsements_count)->toBe(1)
        ->and($futureDated->refresh()->endorsements_count)->toBe(2);
});

it('zeroes the count for a mod whose endorsements were all cascaded away', function (): void {
    $mod = Mod::factory()->create();
    $endorsement = ModEndorsement::factory()->for($mod)->create();

    (new UpdateEndorsementsJob)->handle();
    expect($mod->refresh()->endorsements_count)->toBe(1);

    $endorsement->user->delete();

    (new UpdateEndorsementsJob)->handle();
    expect($mod->refresh()->endorsements_count)->toBe(0);
});
