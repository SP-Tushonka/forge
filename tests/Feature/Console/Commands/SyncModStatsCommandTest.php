<?php

declare(strict_types=1);

use App\Jobs\SyncModStatsForDayJob;
use App\Models\ModVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ModStatsFixtures;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
});

it('queues one job per day in the range', function (): void {
    $this->artisan('app:sync-mod-stats', ['--from' => 2, '--to' => 0])->assertSuccessful();

    expect(Queue::pushed(SyncModStatsForDayJob::class)->pluck('date')->sort()->values()->all())
        ->toBe(['2026-09-16', '2026-09-17', '2026-09-18']);
});

it('syncs today and yesterday by default', function (): void {
    $this->artisan('app:sync-mod-stats')->assertSuccessful();

    Queue::assertPushed(SyncModStatsForDayJob::class, 2);
});

it('rejects a --to further back than --from', function (): void {
    $this->artisan('app:sync-mod-stats', ['--from' => 1, '--to' => 3])->assertFailed();

    Queue::assertNothingPushed();
});

it('backfills from the first recorded download', function (): void {
    ModStatsFixtures::download(ModVersion::factory()->create(), CarbonImmutable::now('UTC')->subDays(50)->toDateTimeString());

    $this->artisan('app:sync-mod-stats', ['--backfill' => true])->assertSuccessful();

    Queue::assertPushed(SyncModStatsForDayJob::class, 51);
});

it('backfills at least the Cloudflare window when there are no downloads', function (): void {
    $this->artisan('app:sync-mod-stats', ['--backfill' => true])->assertSuccessful();

    Queue::assertPushed(SyncModStatsForDayJob::class, 31);
});

it('never backfills past the retention window', function (): void {
    ModStatsFixtures::download(ModVersion::factory()->create(), CarbonImmutable::now('UTC')->subDays(400)->toDateTimeString());

    $this->artisan('app:sync-mod-stats', ['--backfill' => true])->assertSuccessful();

    Queue::assertPushed(SyncModStatsForDayJob::class, 241);
});
