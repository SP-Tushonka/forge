<?php

declare(strict_types=1);

use App\Enums\ModStatsSource;
use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersion;
use App\Models\ModVersionDailyDownload;
use App\Support\ModStats\ModStatsWriter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->writer = new ModStatsWriter;
    $this->day = CarbonImmutable::parse('2026-09-17', 'UTC');
    $this->version = ModVersion::factory()->create();
    $this->modId = $this->version->mod_id;
    $this->rows = [
        ['mod_version_id' => $this->version->id, 'mod_id' => $this->modId, 'country_code' => 'DE', 'downloads' => 3],
        ['mod_version_id' => $this->version->id, 'mod_id' => $this->modId, 'country_code' => 'US', 'downloads' => 2],
    ];
});

it('writes all three tables from one set of download rows', function (): void {
    expect($this->writer->writeDownloads($this->day, $this->rows))->toBeTrue();

    expect(ModDailyStat::query()->sole()->only(['mod_id', 'downloads', 'views']))
        ->toBe(['mod_id' => $this->modId, 'downloads' => 5, 'views' => 0])
        ->and(ModVersionDailyDownload::query()->sole()->downloads)->toBe(5)
        ->and(ModCountryDailyDownload::query()->orderBy('country_code')->pluck('downloads', 'country_code')->all())
        ->toBe(['DE' => 3, 'US' => 2]);
});

it('writes nothing when the counts have not changed', function (): void {
    $this->writer->writeDownloads($this->day, $this->rows);
    $this->travel(15)->minutes();

    expect($this->writer->writeDownloads($this->day, $this->rows))->toBeFalse()
        ->and(ModDailyStat::query()->sole()->updated_at?->toDateTimeString())->toBe('2026-09-18 12:00:00');
});

it('updates only the rows whose count changed', function (): void {
    $this->writer->writeDownloads($this->day, $this->rows);
    $this->travel(15)->minutes();

    $this->rows[1]['downloads'] = 4;
    expect($this->writer->writeDownloads($this->day, $this->rows))->toBeTrue();

    $countries = ModCountryDailyDownload::query()->get()->keyBy('country_code');
    expect($countries['US']->downloads)->toBe(4)
        ->and($countries['DE']->updated_at?->toDateTimeString())->toBe('2026-09-18 12:00:00')
        ->and(ModDailyStat::query()->sole()->downloads)->toBe(7);
});

it('removes rows the source no longer reports and keeps the other column', function (): void {
    $this->writer->writeDownloads($this->day, $this->rows);
    $this->writer->writeViews($this->day, [$this->modId => 40]);

    expect($this->writer->writeDownloads($this->day, []))->toBeTrue();

    expect(ModVersionDailyDownload::query()->count())->toBe(0)
        ->and(ModCountryDailyDownload::query()->count())->toBe(0)
        ->and(ModDailyStat::query()->sole()->only(['downloads', 'views']))->toBe(['downloads' => 0, 'views' => 40]);
});

it('deletes a mod row once both of its counts are zero', function (): void {
    $this->writer->writeViews($this->day, [$this->modId => 40]);

    $this->writer->writeViews($this->day, []);

    expect(ModDailyStat::query()->count())->toBe(0);
});

it('writes views without touching downloads', function (): void {
    $this->writer->writeDownloads($this->day, $this->rows);

    expect($this->writer->writeViews($this->day, [$this->modId => 12]))->toBeTrue()
        ->and(ModDailyStat::query()->sole()->only(['downloads', 'views']))->toBe(['downloads' => 5, 'views' => 12]);
});

it('drops counts for mods that no longer exist', function (): void {
    $known = Mod::factory()->create();

    expect($this->writer->knownModsOnly([$known->id => 3, 999_999 => 8]))->toBe([$known->id => 3]);
});

it('totals a source for a day', function (): void {
    $this->writer->writeDownloads($this->day, $this->rows);

    expect($this->writer->storedTotal($this->day, ModStatsSource::Downloads))->toBe(5)
        ->and($this->writer->storedTotal($this->day, ModStatsSource::Views))->toBe(0);
});
