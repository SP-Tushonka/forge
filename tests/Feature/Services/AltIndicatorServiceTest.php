<?php

declare(strict_types=1);

use App\Enums\AltIndicatorType;
use App\Models\Comment;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\AltIndicatorService;
use App\Support\DataTransferObjects\AltIndicator;
use Illuminate\Support\Facades\DB;

/**
 * @param  array<string, mixed>  $overrides
 */
function indicatorTrackEvent(int $visitorId, string $ip, array $overrides = []): void
{
    DB::table('tracking_events')->insert(array_merge([
        'event_name' => 'login',
        'is_moderation_action' => false,
        'ip' => $ip,
        'useragent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0',
        'platform' => 'Linux',
        'browser' => 'Firefox',
        'languages' => '["en-US","de"]',
        'country_code' => 'DE',
        'visitor_type' => User::class,
        'visitor_id' => $visitorId,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  list<AltIndicator>  $indicators
 * @return list<string>
 */
function indicatorValues(array $indicators, AltIndicatorType $type): array
{
    return array_values(array_map(
        fn (AltIndicator $indicator): string => $indicator->value,
        array_filter($indicators, fn (AltIndicator $indicator): bool => $indicator->type === $type),
    ));
}

it('lists every indicator type from devices, tracking events, comments and the email address', function (): void {
    $user = User::factory()->create(['email' => 'evader@Example.org']);
    UserDevice::factory()->for($user)->create(['device_hash' => str_repeat('a', 64), 'last_ip' => '198.51.100.7', 'country_code' => 'FR']);
    indicatorTrackEvent($user->id, '84.12.3.77');
    Comment::factory()->createQuietly(['user_id' => $user->id, 'user_ip' => '2a02:8108:1a40:3e00::1']);

    $indicators = resolve(AltIndicatorService::class)->forUser($user);

    expect(indicatorValues($indicators, AltIndicatorType::Device))->toBe([str_repeat('a', 64)])
        ->and(indicatorValues($indicators, AltIndicatorType::Ip))->toEqualCanonicalizing(['84.12.3.77', '198.51.100.7', '2a02:8108:1a40:3e00::1'])
        ->and(indicatorValues($indicators, AltIndicatorType::IpRange))->toEqualCanonicalizing(['84.12.3.0/24', '198.51.100.0/24', '2a02:8108:1a40:3e00::/64'])
        ->and(indicatorValues($indicators, AltIndicatorType::EmailDomain))->toBe(['example.org'])
        ->and(indicatorValues($indicators, AltIndicatorType::BrowserPrint))->toBe(['Linux|Firefox|en-US,de'])
        ->and(indicatorValues($indicators, AltIndicatorType::Country))->toEqualCanonicalizing(['DE', 'FR'])
        ->and(indicatorValues($indicators, AltIndicatorType::UserAgent))->toHaveCount(2);
});

it('ignores moderation actions and ban events filed against the account', function (): void {
    $user = User::factory()->create();
    indicatorTrackEvent($user->id, '203.0.113.9', ['is_moderation_action' => true]);
    indicatorTrackEvent($user->id, '203.0.113.10', ['event_name' => 'user_banned']);

    $indicators = resolve(AltIndicatorService::class)->forUser($user);

    expect(indicatorValues($indicators, AltIndicatorType::Ip))->toBe([]);
});

it('counts the other accounts sharing each identifier, and never counts qualifiers', function (): void {
    $user = User::factory()->create(['email' => 'one@rare.test']);
    $other = User::factory()->create(['email' => 'two@rare.test']);
    $third = User::factory()->create(['email' => 'three@elsewhere.test']);
    indicatorTrackEvent($user->id, '84.12.3.77');
    indicatorTrackEvent($other->id, '84.12.3.77');
    indicatorTrackEvent($third->id, '84.12.3.200');

    $byKey = collect(resolve(AltIndicatorService::class)->forUser($user))
        ->keyBy(fn (AltIndicator $indicator): string => $indicator->type->value.'|'.$indicator->value);

    expect($byKey['ip|84.12.3.77']->sharedWith)->toBe(1)
        ->and($byKey['ip_range|84.12.3.0/24']->sharedWith)->toBe(2)
        ->and($byKey['email_domain|rare.test']->sharedWith)->toBe(1)
        ->and($byKey['country|DE']->sharedWith)->toBeNull();
});

it('keeps devices the member has signed out', function (): void {
    $user = User::factory()->create();
    UserDevice::factory()->for($user)->revoked()->create(['device_hash' => str_repeat('b', 64)]);

    expect(indicatorValues(resolve(AltIndicatorService::class)->forUser($user), AltIndicatorType::Device))->toBe([str_repeat('b', 64)]);
});

it('loads the indicator sets of several accounts at once for matching', function (): void {
    $first = User::factory()->create(['email' => 'a@one.test']);
    $second = User::factory()->create(['email' => 'b@two.test']);
    UserDevice::factory()->for($first)->create(['device_hash' => str_repeat('c', 64), 'last_ip' => null]);
    indicatorTrackEvent($second->id, '2a02:8108:1a40:3e00::9');

    $sets = resolve(AltIndicatorService::class)->forUsers([$first->id, $second->id]);

    expect($sets[$first->id]->has(AltIndicatorType::Device, str_repeat('c', 64)))->toBeTrue()
        ->and($sets[$first->id]->has(AltIndicatorType::EmailDomain, 'one.test'))->toBeTrue()
        ->and($sets[$second->id]->has(AltIndicatorType::IpRange, '2a02:8108:1a40:3e00::/64'))->toBeTrue()
        ->and($sets[$second->id]->has(AltIndicatorType::BrowserPrint, 'Linux|Firefox|en-US,de'))->toBeTrue()
        ->and($sets[$second->id]->has(AltIndicatorType::Device, str_repeat('c', 64)))->toBeFalse();
});

it('finds the accounts holding an identifier, and nobody through a qualifier', function (): void {
    $user = User::factory()->create();
    indicatorTrackEvent($user->id, '84.12.3.77');
    $service = resolve(AltIndicatorService::class);

    expect($service->accountsWith(AltIndicatorType::IpRange, '84.12.3.0/24'))->toBe([$user->id])
        ->and($service->accountsWith(AltIndicatorType::IpRange, '84.12.30.0/24'))->toBe([])
        ->and($service->accountsWith(AltIndicatorType::Country, 'DE'))->toBe([]);
});
