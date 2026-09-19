<?php

declare(strict_types=1);

use App\Enums\ModStatsSource;
use App\Services\ModStats\ModStatsState;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->state = resolve(ModStatsState::class);
});

it('fails when a source has never synced', function (): void {
    $this->state->markSynced(ModStatsSource::Downloads);

    $this->artisan('app:mod-stats-status')
        ->expectsOutputToContain('views: never synced')
        ->assertFailed();
});

it('succeeds when both sources synced recently', function (): void {
    $this->state->markSynced(ModStatsSource::Downloads);
    $this->state->markSynced(ModStatsSource::Views);
    $this->travel(90)->minutes();

    $this->artisan('app:mod-stats-status')->assertSuccessful();
});

it('fails when one source is stale', function (): void {
    $this->state->markSynced(ModStatsSource::Views);
    $this->travel(3)->hours();
    $this->state->markSynced(ModStatsSource::Downloads);

    $this->artisan('app:mod-stats-status')
        ->expectsOutputToContain('views: last synced 180 minute(s) ago')
        ->assertFailed();
});
