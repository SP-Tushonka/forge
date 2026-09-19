<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Support\ModStats\DownloadStatsCollector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ModStatsFixtures;

beforeEach(function (): void {
    Queue::fake();
    $this->version = ModVersion::factory()->create();
    $this->collector = new DownloadStatsCollector;
    $this->day = CarbonImmutable::parse('2026-09-17', 'UTC');
});

it('counts one UTC day of downloads per version and country', function (): void {
    ModStatsFixtures::download($this->version, '2026-09-17 00:00:00', 'DE', 2);
    ModStatsFixtures::download($this->version, '2026-09-17 23:59:59', 'US');
    ModStatsFixtures::download($this->version, '2026-09-16 23:59:59', 'US');
    ModStatsFixtures::download($this->version, '2026-09-18 00:00:00', 'US');

    $rows = collect($this->collector->forDay($this->day))->sortBy('country_code')->values()->all();

    expect($rows)->toBe([
        ['mod_version_id' => $this->version->id, 'mod_id' => $this->version->mod_id, 'country_code' => 'DE', 'downloads' => 2],
        ['mod_version_id' => $this->version->id, 'mod_id' => $this->version->mod_id, 'country_code' => 'US', 'downloads' => 1],
    ]);
});

it('normalises missing, blank and lowercase countries', function (): void {
    ModStatsFixtures::download($this->version, '2026-09-17 10:00:00', null);
    ModStatsFixtures::download($this->version, '2026-09-17 10:00:00', '');
    ModStatsFixtures::download($this->version, '2026-09-17 10:00:00', 'de');
    ModStatsFixtures::download($this->version, '2026-09-17 10:00:00', 'DE');

    $rows = collect($this->collector->forDay($this->day))->sortBy('country_code')->pluck('downloads', 'country_code')->all();

    expect($rows)->toBe(['DE' => 2, 'XX' => 2]);
});

it('ignores other events and other visitable types', function (): void {
    $mod = Mod::factory()->create();

    DB::table('tracking_events')->insert([
        [
            'event_name' => TrackingEventType::MOD_EDIT->value,
            'visitable_type' => $this->version->getMorphClass(),
            'visitable_id' => $this->version->id,
            'is_moderation_action' => false,
            'created_at' => '2026-09-17 10:00:00',
            'updated_at' => '2026-09-17 10:00:00',
        ],
        [
            'event_name' => TrackingEventType::MOD_DOWNLOAD->value,
            'visitable_type' => $mod->getMorphClass(),
            'visitable_id' => $this->version->id,
            'is_moderation_action' => false,
            'created_at' => '2026-09-17 10:00:00',
            'updated_at' => '2026-09-17 10:00:00',
        ],
    ]);

    expect($this->collector->forDay($this->day))->toBe([]);
});
