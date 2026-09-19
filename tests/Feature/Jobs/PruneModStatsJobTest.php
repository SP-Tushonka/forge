<?php

declare(strict_types=1);

use App\Jobs\PruneModStatsJob;
use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersion;
use App\Models\ModVersionDailyDownload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

it('deletes rows older than the retention window from all three tables', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $version = ModVersion::factory()->create();
    $oldest = CarbonImmutable::now('UTC')->startOfDay()->subDays(PruneModStatsJob::RETENTION_DAYS);

    foreach ([$oldest->subDay(), $oldest] as $day) {
        $date = $day->toDateString();
        ModDailyStat::factory()->create(['mod_id' => $version->mod_id, 'date' => $date]);
        ModVersionDailyDownload::factory()->create(['mod_version_id' => $version->id, 'mod_id' => $version->mod_id, 'date' => $date]);
        ModCountryDailyDownload::factory()->create(['mod_id' => $version->mod_id, 'date' => $date, 'country_code' => 'DE']);
    }

    (new PruneModStatsJob)->handle();

    foreach ([ModDailyStat::class, ModVersionDailyDownload::class, ModCountryDailyDownload::class] as $model) {
        expect($model::query()->pluck('date')->all())->toBe([$oldest->toDateString()]);
    }
});
