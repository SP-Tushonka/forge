<?php

declare(strict_types=1);

use App\Enums\AltIndicatorType;
use App\Enums\AltWatchMatchMode;
use App\Models\User;
use App\Services\AltWatchMatchService;
use App\Support\DataTransferObjects\AltIndicator;
use App\Support\DataTransferObjects\AltIndicatorSet;
use App\Support\DataTransferObjects\AltWatchDraft;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * @param  list<array{0: AltIndicatorType, 1: string}>  $values
 */
function matchDraft(AltWatchMatchMode $mode, array $values, ?int $watchedUserId = null): AltWatchDraft
{
    return new AltWatchDraft($watchedUserId, $mode, array_map(fn (array $value): AltIndicator => new AltIndicator($value[0], $value[1], $value[1]), $values));
}

/**
 * @param  list<array{0: AltIndicatorType, 1: string}>  $values
 */
function matchSet(array $values): AltIndicatorSet
{
    $set = [];

    foreach ($values as [$type, $value]) {
        $set[$type->value][$value] = true;
    }

    return new AltIndicatorSet($set);
}

function matchTrackEvent(int $visitorId, string $ip, string $browser = 'Firefox'): void
{
    DB::table('tracking_events')->insert([
        'event_name' => 'login',
        'is_moderation_action' => false,
        'ip' => $ip,
        'platform' => 'Linux',
        'browser' => $browser,
        'languages' => '["en-US"]',
        'visitor_type' => User::class,
        'visitor_id' => $visitorId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('matches on any ticked identifier in "any" mode', function (): void {
    $draft = matchDraft(AltWatchMatchMode::Any, [[AltIndicatorType::Device, 'd1'], [AltIndicatorType::Ip, '84.12.3.77']]);

    $keys = resolve(AltWatchMatchService::class)->matchedKeys($draft, matchSet([[AltIndicatorType::Ip, '84.12.3.77']]));

    expect($keys)->toBe([$draft->indicators[1]->key()]);
});

it('needs every identifier in "all" mode', function (): void {
    $draft = matchDraft(AltWatchMatchMode::All, [[AltIndicatorType::Device, 'd1'], [AltIndicatorType::IpRange, '84.12.3.0/24']]);
    $service = resolve(AltWatchMatchService::class);

    expect($service->matchedKeys($draft, matchSet([[AltIndicatorType::Device, 'd1']])))->toBeNull()
        ->and($service->matchedKeys($draft, matchSet([[AltIndicatorType::Device, 'd1'], [AltIndicatorType::IpRange, '84.12.3.0/24']])))->toHaveCount(2);
});

it('requires every qualifier on top of the identifiers, and never matches on qualifiers alone', function (): void {
    $service = resolve(AltWatchMatchService::class);
    $withQualifier = matchDraft(AltWatchMatchMode::Any, [[AltIndicatorType::Ip, '84.12.3.77'], [AltIndicatorType::Country, 'DE']]);
    $qualifierOnly = matchDraft(AltWatchMatchMode::Any, [[AltIndicatorType::Country, 'DE']]);

    expect($service->matchedKeys($withQualifier, matchSet([[AltIndicatorType::Ip, '84.12.3.77']])))->toBeNull()
        ->and($service->matchedKeys($withQualifier, matchSet([[AltIndicatorType::Ip, '84.12.3.77'], [AltIndicatorType::Country, 'DE']])))->toHaveCount(2)
        ->and($service->matchedKeys($qualifierOnly, matchSet([[AltIndicatorType::Country, 'DE']])))->toBeNull();
});

it('previews the accounts matching today, leaving out the watched user', function (): void {
    $watched = User::factory()->create();
    $firefox = User::factory()->create();
    $chrome = User::factory()->create();
    matchTrackEvent($watched->id, '84.12.3.77');
    matchTrackEvent($firefox->id, '84.12.3.77');
    matchTrackEvent($chrome->id, '84.12.3.99', 'Chrome');

    $draft = matchDraft(AltWatchMatchMode::Any, [[AltIndicatorType::IpRange, '84.12.3.0/24'], [AltIndicatorType::BrowserPrint, 'Linux|Firefox|en-US']], $watched->id);

    $preview = resolve(AltWatchMatchService::class)->preview($draft);

    expect(array_keys($preview->matches))->toBe([$firefox->id])
        ->and($preview->matches[$firefox->id])->toHaveCount(2)
        ->and($preview->tooBroad())->toBeFalse();
});

it('flags a draft that matches more than 100 accounts as too broad', function (): void {
    User::factory()->count(101)->sequence(fn (Sequence $sequence): array => ['email' => 'member'.$sequence->index.'@crowd.test'])->create();

    $preview = resolve(AltWatchMatchService::class)->preview(matchDraft(AltWatchMatchMode::Any, [[AltIndicatorType::EmailDomain, 'crowd.test']]));

    expect($preview->count())->toBe(101)
        ->and($preview->tooBroad())->toBeTrue();
});
