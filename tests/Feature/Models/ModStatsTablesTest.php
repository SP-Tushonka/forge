<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersion;
use App\Models\ModVersionDailyDownload;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

it('deletes a mod\'s stats together with the mod', function (): void {
    $mod = Mod::factory()->create();
    $version = ModVersion::factory()->for($mod)->create();

    ModDailyStat::factory()->create(['mod_id' => $mod->id, 'date' => '2026-09-18']);
    ModVersionDailyDownload::factory()->create(['mod_version_id' => $version->id, 'mod_id' => $mod->id, 'date' => '2026-09-18']);
    ModCountryDailyDownload::factory()->create(['mod_id' => $mod->id, 'date' => '2026-09-18', 'country_code' => 'DE']);

    $mod->delete();

    expect(ModDailyStat::query()->count())->toBe(0)
        ->and(ModVersionDailyDownload::query()->count())->toBe(0)
        ->and(ModCountryDailyDownload::query()->count())->toBe(0);
});

it('deletes a version\'s stats together with the version', function (): void {
    $version = ModVersion::factory()->create();
    ModVersionDailyDownload::factory()->create(['mod_version_id' => $version->id, 'mod_id' => $version->mod_id, 'date' => '2026-09-18']);

    $version->delete();

    expect(ModVersionDailyDownload::query()->count())->toBe(0);
});

it('allows one row per mod and day', function (): void {
    $mod = Mod::factory()->create();
    ModDailyStat::factory()->create(['mod_id' => $mod->id, 'date' => '2026-09-18']);

    ModDailyStat::factory()->create(['mod_id' => $mod->id, 'date' => '2026-09-18']);
})->throws(UniqueConstraintViolationException::class);

it('casts counts to integers and keeps the date as a plain day', function (): void {
    $stat = ModDailyStat::factory()->create(['date' => '2026-09-18', 'downloads' => 5, 'views' => 7])->fresh();

    expect($stat->downloads)->toBe(5)
        ->and($stat->views)->toBe(7)
        ->and($stat->date)->toBe('2026-09-18');
});
