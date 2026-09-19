<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersion;
use App\Models\ModVersionDailyDownload;
use App\Models\SptVersion;
use App\Models\User;
use App\Services\ModStats\ModStatsService;
use App\Services\ModStats\ModStatsState;
use App\Support\DataTransferObjects\StatsRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'));
    $this->mod = Mod::factory()->create();
    $this->service = resolve(ModStatsService::class);
    $this->day = function (string $date, int $downloads, int $views = 0, ?Mod $mod = null): void {
        ModDailyStat::factory()->create([
            'mod_id' => ($mod ?? $this->mod)->id,
            'date' => $date,
            'downloads' => $downloads,
            'views' => $views,
        ]);
    };
    $this->tile = fn (array $report, string $key): array => collect($report['summary'])->firstWhere('key', $key);
});

it('totals the range including today and compares complete days', function (): void {
    for ($day = 6; $day <= 11; $day++) {
        ($this->day)(sprintf('2026-09-%02d', $day), 10);
    }
    for ($day = 12; $day <= 17; $day++) {
        ($this->day)(sprintf('2026-09-%02d', $day), 20);
    }
    ($this->day)('2026-09-18', 5);

    $tile = ($this->tile)($this->service->forMod($this->mod, StatsRange::make(7, 'daily')), 'downloads');

    expect($tile['total'])->toBe(125)
        ->and($tile['change'])->toBe(['value' => 100.0, 'absolute' => false]);
});

it('shows an absolute change when the previous period is small', function (): void {
    ($this->day)('2026-09-06', 2);
    ($this->day)('2026-09-12', 5);

    $tile = ($this->tile)($this->service->forMod($this->mod, StatsRange::make(7, 'daily')), 'downloads');

    expect($tile['change'])->toBe(['value' => 3, 'absolute' => true]);
});

it('has no change figure when collection started inside the previous period', function (): void {
    ($this->day)('2026-09-09', 2);
    ($this->day)('2026-09-12', 5);

    $tile = ($this->tile)($this->service->forMod($this->mod, StatsRange::make(7, 'daily')), 'downloads');

    expect($tile['change'])->toBeNull();
});

it('leaves days before collection started as gaps, not zeros', function (): void {
    ($this->day)('2026-09-10', 4);

    $traffic = collect($this->service->forMod($this->mod, StatsRange::make(30, 'daily'))['traffic'])->keyBy('date');

    expect($traffic['2026-09-09']['downloads'])->toBeNull()
        ->and($traffic['2026-09-10']['downloads'])->toBe(4)
        ->and($traffic['2026-09-11']['downloads'])->toBe(0)
        ->and($traffic['2026-09-10']['views'])->toBeNull();
});

it('labels releases and marks today as partial', function (): void {
    ModVersion::factory()->for($this->mod)->create(['version' => '1.2.0', 'disabled' => false, 'published_at' => '2026-09-15 10:00:00']);

    $traffic = collect($this->service->forMod($this->mod, StatsRange::make(7, 'daily'))['traffic']);

    expect($traffic->firstWhere('date', '2026-09-15')['release'])->toBe('1.2.0')
        ->and($traffic->last()['partial'])->toBeTrue()
        ->and($traffic->last()['label'])->toBe('Sep 18 (so far)');
});

it('sums days into ISO weeks', function (): void {
    ($this->day)('2026-09-13', 5);
    ($this->day)('2026-09-14', 3);
    ($this->day)('2026-09-16', 4);

    $traffic = collect($this->service->forMod($this->mod, StatsRange::make(30, 'weekly'))['traffic'])->keyBy('date');

    expect($traffic['2026-09-07']['downloads'])->toBe(5)
        ->and($traffic['2026-09-14']['downloads'])->toBe(7);
});

it('charts the five newest versions and folds the rest into older versions', function (): void {
    foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0', '2.0.0'] as $number) {
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $number));
        $version = ModVersion::factory()->for($this->mod)->create([
            'version' => $number,
            'version_major' => $major,
            'version_minor' => $minor,
            'version_patch' => $patch,
            'version_labels' => '',
        ]);
        ModVersionDailyDownload::factory()->create(['mod_version_id' => $version->id, 'mod_id' => $this->mod->id, 'date' => '2026-09-17', 'downloads' => 2]);
    }

    $versions = $this->service->forMod($this->mod, StatsRange::make(7, 'daily'))['versions'];

    expect($versions['labels'])->toBe(['v1' => '2.0.0', 'v2' => '1.4.0', 'v3' => '1.3.0', 'v4' => '1.2.0', 'v5' => '1.1.0', 'older' => 'Older versions'])
        ->and(collect($versions['rows'])->firstWhere('date', '2026-09-17'))->toMatchArray(['v1' => 2, 'v5' => 2, 'older' => 2]);
});

it('groups small countries into other and never exposes them', function (): void {
    foreach (['DE' => 50, 'FR' => 12, 'IS' => 3, 'XX' => 5] as $code => $downloads) {
        ModCountryDailyDownload::factory()->create(['mod_id' => $this->mod->id, 'date' => '2026-09-17', 'country_code' => $code, 'downloads' => $downloads]);
    }

    $report = $this->service->forMod($this->mod, StatsRange::make(7, 'daily'));

    expect(array_column($report['countries'], 'label'))->toBe(['Germany', 'France', 'Other (1 country)', 'Unknown'])
        ->and(array_column($report['countries'], 'downloads'))->toBe([50, 12, 3, 5])
        ->and(json_encode($report))->not->toContain('Iceland')
        ->and(json_encode($report))->not->toContain('"IS"');
});

it('includes engagement in the summary', function (): void {
    Comment::factory()->create([
        'commentable_type' => $this->mod->getMorphClass(),
        'commentable_id' => $this->mod->id,
        'created_at' => '2026-09-16 10:00:00',
    ]);

    $tile = ($this->tile)($this->service->forMod($this->mod, StatsRange::make(7, 'daily')), 'comments');

    expect($tile['total'])->toBe(1)
        ->and($tile['change'])->toBe(['value' => 1, 'absolute' => true]);
});

it('lists downstream mods with their downloads in the range', function (): void {
    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());
    $dependent = Mod::factory()->create(['name' => 'Weapon Pack']);
    $version = ModVersion::factory()->for($dependent)->create(['spt_version_constraint' => '1.0.0', 'disabled' => false, 'published_at' => now()->subDay()]);
    Dependency::factory()->forModVersion($version)->create(['dependent_mod_id' => $this->mod->id]);
    ($this->day)('2026-09-17', 42, 0, $dependent);

    $downstream = $this->service->forMod($this->mod, StatsRange::make(7, 'daily'))['downstream'];

    expect($downstream['count'])->toBe(1)
        ->and($downstream['top'][0])->toMatchArray(['name' => 'Weapon Pack', 'downloads' => 42]);
});

it('serves a fresh report once the stats version changes', function (): void {
    ($this->day)('2026-09-17', 4);
    $range = StatsRange::make(7, 'daily');
    expect(($this->tile)($this->service->forMod($this->mod, $range), 'downloads')['total'])->toBe(4);

    ModDailyStat::query()->update(['downloads' => 9]);
    expect(($this->tile)($this->service->forMod($this->mod, $range), 'downloads')['total'])->toBe(4);

    resolve(ModStatsState::class)->bumpVersion();
    expect(($this->tile)($this->service->forMod($this->mod, $range), 'downloads')['total'])->toBe(9);
});

it('builds the overview from owned and co-authored mods only', function (): void {
    $user = User::factory()->create();
    $owned = Mod::factory()->create(['owner_id' => $user->id, 'name' => 'Alpha']);
    $coAuthored = Mod::factory()->unpublished()->create(['name' => 'Beta']);
    $coAuthored->additionalAuthors()->attach($user);
    Mod::factory()->create(['name' => 'Gamma']);
    ($this->day)('2026-09-17', 4, 10, $owned);
    ($this->day)('2026-09-17', 6, 0, $coAuthored);

    $report = $this->service->forUser($user, StatsRange::make(7, 'daily'));

    expect(array_column($report['mods'], 'name'))->toBe(['Alpha', 'Beta'])
        ->and(array_column($report['mods'], 'status'))->toBe([null, 'Unpublished'])
        ->and(($this->tile)($report, 'downloads')['total'])->toBe(10)
        ->and($report['mods'][0]['url'])->toBe(route('mod.stats', ['modId' => $owned->id, 'slug' => $owned->slug]));
});
