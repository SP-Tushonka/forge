<?php

declare(strict_types=1);

use App\Enums\ModStatsSource;
use App\Models\ModDailyStat;
use App\Support\ModStats\ModStatsState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->state = resolve(ModStatsState::class);
});

it('starts the stats version at zero and increments it', function (): void {
    expect($this->state->version())->toBe(0);

    $this->state->bumpVersion();
    $this->state->bumpVersion();

    expect($this->state->version())->toBe(2);
});

it('remembers when each source last synced', function (): void {
    expect($this->state->lastSynced(ModStatsSource::Views))->toBeNull();

    $this->state->markSynced(ModStatsSource::Views);

    expect($this->state->lastSynced(ModStatsSource::Views)?->toIso8601ZuluString())->toBe('2026-09-18T12:00:00Z')
        ->and($this->state->lastSynced(ModStatsSource::Downloads))->toBeNull();
});

it('finds the first day each source has data for', function (): void {
    ModDailyStat::factory()->create(['date' => '2026-08-11', 'downloads' => 4, 'views' => 0]);
    ModDailyStat::factory()->create(['date' => '2026-08-20', 'downloads' => 1, 'views' => 9]);

    expect($this->state->dataSince(ModStatsSource::Downloads)?->toDateString())->toBe('2026-08-11')
        ->and($this->state->dataSince(ModStatsSource::Views)?->toDateString())->toBe('2026-08-20');
});

it('has no data-since date before anything is synced', function (): void {
    expect($this->state->dataSince(ModStatsSource::Downloads))->toBeNull();
});

it('recomputes the data-since date after the stats version changes', function (): void {
    ModDailyStat::factory()->create(['date' => '2026-09-01', 'downloads' => 3]);
    expect($this->state->dataSince(ModStatsSource::Downloads)?->toDateString())->toBe('2026-09-01');

    ModDailyStat::factory()->create(['date' => '2026-08-11', 'downloads' => 3]);
    $this->state->bumpVersion();

    expect($this->state->dataSince(ModStatsSource::Downloads)?->toDateString())->toBe('2026-08-11');
});
