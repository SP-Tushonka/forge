<?php

declare(strict_types=1);

use App\Enums\AltIndicatorType;
use App\Enums\AltMatchOutcome;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;
use App\Models\AltWatchMatch;
use App\Models\User;

it('treats a watch as active until it ends or expires', function (): void {
    $active = AltWatch::factory()->create();
    $ended = AltWatch::factory()->ended()->create();
    $expired = AltWatch::factory()->expired()->create();

    expect(AltWatch::query()->active()->pluck('id')->all())->toBe([$active->id])
        ->and($active->status())->toBe('active')
        ->and($ended->status())->toBe('ended')
        ->and($expired->status())->toBe('expired')
        ->and($expired->isActive())->toBeFalse();
});

it('keeps a watch and its saved name when the watched account is deleted', function (): void {
    $user = User::factory()->create(['name' => 'Evader']);
    $watch = AltWatch::factory()->for($user, 'watchedUser')->create();

    $user->delete();
    $watch->refresh();

    expect($watch->watched_user_id)->toBeNull()
        ->and($watch->watchedName())->toBe('Evader');
});

it('deletes indicators and matches with their watch, and matches with the matched account', function (): void {
    $watch = AltWatch::factory()->create();
    AltWatchIndicator::factory()->for($watch, 'watch')->create();
    AltWatchMatch::factory()->for($watch, 'watch')->create();
    $other = AltWatchMatch::factory()->create();

    User::query()->whereKey($other->user_id)->delete();
    $watch->delete();

    expect(AltWatchIndicator::query()->count())->toBe(0)
        ->and(AltWatchMatch::query()->count())->toBe(0);
});

it('names the kinds of indicator a match hit, and lists only unreviewed new matches', function (): void {
    $watch = AltWatch::factory()->create();
    $device = AltWatchIndicator::factory()->for($watch, 'watch')->create(['type' => AltIndicatorType::Device, 'value' => str_repeat('a', 64)]);
    $range = AltWatchIndicator::factory()->for($watch, 'watch')->create(['type' => AltIndicatorType::IpRange, 'value' => '203.0.113.0/24']);
    AltWatchIndicator::factory()->for($watch, 'watch')->create(['type' => AltIndicatorType::Country, 'value' => 'DE']);

    $fresh = AltWatchMatch::factory()->for($watch, 'watch')->create(['matched_indicator_ids' => [$device->id, $range->id]]);
    AltWatchMatch::factory()->for($watch, 'watch')->baseline()->create();
    AltWatchMatch::factory()->for($watch, 'watch')->reviewed(AltMatchOutcome::Confirmed)->create();

    expect($fresh->matchedKinds())->toEqualCanonicalizing(['Device', 'IP range'])
        ->and(AltWatchMatch::query()->unreviewed()->pluck('id')->all())->toBe([$fresh->id]);
});

it('records who ended a watch and who reviewed a match', function (): void {
    $admin = User::factory()->admin()->create();
    $watch = AltWatch::factory()->create();
    $match = AltWatchMatch::factory()->for($watch, 'watch')->create();

    $watch->end($admin);
    $match->review(AltMatchOutcome::Confirmed, $admin);

    expect($watch->refresh()->ended_by)->toBe($admin->id)
        ->and($watch->status())->toBe('ended')
        ->and($match->refresh()->review_outcome)->toBe(AltMatchOutcome::Confirmed)
        ->and($match->reviewed_by)->toBe($admin->id);
});
