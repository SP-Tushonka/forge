<?php

declare(strict_types=1);

use App\Enums\ModStatsSource;
use App\Jobs\SyncModStatsForDayJob;
use App\Models\ModDailyStat;
use App\Models\ModVersion;
use App\Services\ModStats\ModStatsState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ModStatsFixtures;

beforeEach(function (): void {
    Queue::fake();
    config([
        'services.cloudflare.analytics_token' => 'test-token',
        'services.cloudflare.zone_id' => 'zone-123',
        'app.url' => 'https://forge.example.test',
    ]);
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->version = ModVersion::factory()->create();
    $this->modId = $this->version->mod_id;
    $this->state = resolve(ModStatsState::class);
    $this->runJob = fn (string $date) => app()->call([new SyncModStatsForDayJob($date), 'handle']);
});

it('syncs downloads and views for a recent day', function (): void {
    ModStatsFixtures::download($this->version, '2026-09-17 09:00:00', 'DE', 3);
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews("/mod/{$this->modId}/slug", 20),
    ]))]);

    ($this->runJob)('2026-09-17');

    expect(ModDailyStat::query()->sole()->only(['mod_id', 'downloads', 'views']))
        ->toBe(['mod_id' => $this->modId, 'downloads' => 3, 'views' => 20])
        ->and($this->state->lastSynced(ModStatsSource::Downloads))->not->toBeNull()
        ->and($this->state->lastSynced(ModStatsSource::Views))->not->toBeNull()
        ->and($this->state->version())->toBe(2);
});

it('keeps stored views when Cloudflare fails and still syncs downloads', function (): void {
    ModDailyStat::factory()->create(['mod_id' => $this->modId, 'date' => '2026-09-17', 'downloads' => 0, 'views' => 50]);
    ModStatsFixtures::download($this->version, '2026-09-17 09:00:00', 'DE', 3);
    Http::fake(['api.cloudflare.com/*' => Http::response([], 500)]);

    ($this->runJob)('2026-09-17');

    expect(ModDailyStat::query()->sole()->only(['downloads', 'views']))->toBe(['downloads' => 3, 'views' => 50])
        ->and($this->state->lastSynced(ModStatsSource::Views))->toBeNull()
        ->and($this->state->lastSynced(ModStatsSource::Downloads))->not->toBeNull();
});

it('does not ask Cloudflare about days older than its retention', function (): void {
    Http::fake();

    ($this->runJob)('2026-08-18');

    Http::assertNothingSent();
});

it('leaves the stats version alone when nothing changed', function (): void {
    ModStatsFixtures::download($this->version, '2026-09-17 09:00:00', 'DE', 3);
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews("/mod/{$this->modId}/slug", 20),
    ]))]);

    ($this->runJob)('2026-09-17');
    $version = $this->state->version();
    ($this->runJob)('2026-09-17');

    expect($this->state->version())->toBe($version);
});

it('warns when a settled day drifts from the stored total', function (): void {
    ModDailyStat::factory()->create(['mod_id' => $this->modId, 'date' => '2026-09-10', 'downloads' => 0, 'views' => 100]);
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews("/mod/{$this->modId}/slug", 110),
    ]))]);
    Log::spy();

    ($this->runJob)('2026-09-10');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Mod stats drifted on a settled day'
            && $context === ['date' => '2026-09-10', 'source' => 'views', 'stored' => 100, 'fresh' => 110])
        ->once();
    expect(ModDailyStat::query()->sole()->views)->toBe(110);
});

it('does not warn about drift on days that may still change', function (): void {
    ModDailyStat::factory()->create(['mod_id' => $this->modId, 'date' => '2026-09-17', 'downloads' => 0, 'views' => 100]);
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews("/mod/{$this->modId}/slug", 110),
    ]))]);
    Log::spy();

    ($this->runJob)('2026-09-17');

    Log::shouldNotHaveReceived('warning');
});

it('ignores views for mods that no longer exist', function (): void {
    Http::fake(['api.cloudflare.com/*' => Http::response(ModStatsFixtures::cloudflareGroups([
        ModStatsFixtures::pageViews("/mod/{$this->modId}/slug", 3),
        ModStatsFixtures::pageViews('/mod/999999/gone', 5),
    ]))]);

    ($this->runJob)('2026-09-17');

    expect(ModDailyStat::query()->pluck('views', 'mod_id')->all())->toBe([$this->modId => 3]);
});

it('is unique per day', function (): void {
    expect((new SyncModStatsForDayJob('2026-09-17'))->uniqueId())->toBe('2026-09-17');
});
