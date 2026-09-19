<?php

declare(strict_types=1);

use App\Services\CloudflareAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\ModStatsFixtures;

beforeEach(function (): void {
    config([
        'services.cloudflare.analytics_token' => 'test-token',
        'services.cloudflare.zone_id' => 'zone-123',
        'services.cloudflare.api_host' => 'api.example.test',
        'app.url' => 'https://forge.example.test',
    ]);
    $this->service = resolve(CloudflareAnalyticsService::class);
    $this->since = CarbonImmutable::parse('2026-09-17 00:00:00', 'UTC');
    $this->until = CarbonImmutable::parse('2026-09-18 00:00:00', 'UTC');
});

it('returns null when Cloudflare analytics are not configured', function (): void {
    config(['services.cloudflare.analytics_token' => null]);

    expect($this->service->modPageViews($this->since, $this->until))->toBeNull();
});

it('counts views per mod from mod page paths only', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews('/mod/12/realism', 5),
        ModStatsFixtures::pageViews('/mod/12/old-slug/', 3),
        ModStatsFixtures::pageViews('/mod/34/weapons', 2),
        ModStatsFixtures::pageViews('/mod/download/12/realism/1.0.0', 9),
        ModStatsFixtures::pageViews('/mod/12/edit', 4),
        ModStatsFixtures::pageViews('/mod/12/realism/stats', 6),
        ModStatsFixtures::pageViews('/mod/create', 7),
    ]))]);

    expect($this->service->modPageViews($this->since, $this->until))->toBe([12 => 8, 34 => 2]);
});

it('drops loads by Cloudflare-verified bots', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews('/mod/12/realism', 5),
        ModStatsFixtures::pageViews('/mod/12/realism', 50, 'Search Engine Crawler'),
    ]))]);

    expect($this->service->modPageViews($this->since, $this->until))->toBe([12 => 5]);
});

it('queries the site host, not the API host, over the given window', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([]))]);

    $this->service->modPageViews($this->since, $this->until);

    Http::assertSent(fn ($request): bool => data_get($request->data(), 'variables.host') === 'forge.example.test'
        && data_get($request->data(), 'variables.since') === '2026-09-17T00:00:00Z'
        && data_get($request->data(), 'variables.until') === '2026-09-18T00:00:00Z'
        && str_contains((string) data_get($request->data(), 'query'), 'clientRequestPath_like: "/mod/%"'));
});

it('splits the window when a response reaches the row limit', function (): void {
    Http::fakeSequence('api.cloudflare.com/*')
        ->push(ModStatsFixtures::cloudflareGroups(array_fill(0, 10000, ModStatsFixtures::pageViews('/mod/1/a', 1))))
        ->push(ModStatsFixtures::cloudflareGroups([ModStatsFixtures::pageViews('/mod/1/a', 3)]))
        ->push(ModStatsFixtures::cloudflareGroups([ModStatsFixtures::pageViews('/mod/2/b', 4)]));

    expect($this->service->modPageViews($this->since, $this->until))->toBe([1 => 3, 2 => 4]);
    Http::assertSentCount(3);
});

it('returns null when any request fails, even after a split', function (): void {
    Http::fakeSequence('api.cloudflare.com/*')
        ->push(ModStatsFixtures::cloudflareGroups(array_fill(0, 10000, ModStatsFixtures::pageViews('/mod/1/a', 1))))
        ->push(ModStatsFixtures::cloudflareGroups([ModStatsFixtures::pageViews('/mod/1/a', 3)]))
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500);

    expect($this->service->modPageViews($this->since, $this->until))->toBeNull();
});

it('returns null when the GraphQL response carries errors', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(['errors' => [['message' => 'boom']]])]);

    expect($this->service->modPageViews($this->since, $this->until))->toBeNull();
});
