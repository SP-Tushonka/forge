<?php

declare(strict_types=1);

use App\Enums\StatsGrain;
use App\Support\DataTransferObjects\StatsRange;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    // A Friday; its ISO week starts on Monday 2026-09-14.
    $this->today = CarbonImmutable::parse('2026-09-18 15:30:00', 'UTC');
});

it('sanitises values from the URL', function (): void {
    $fallback = StatsRange::make('abc', 'hourly', $this->today);
    $weekly = StatsRange::make('90', 'weekly', $this->today);
    $shortWeekly = StatsRange::make('7', 'weekly', $this->today);

    expect([$fallback->days, $fallback->grain])->toBe([30, StatsGrain::Daily])
        ->and([$weekly->days, $weekly->grain])->toBe([90, StatsGrain::Weekly])
        ->and([$shortWeekly->days, $shortWeekly->grain])->toBe([7, StatsGrain::Daily]);
});

it('covers the given number of days ending today', function (): void {
    $range = StatsRange::make(7, 'daily', $this->today);

    expect($range->start()->toDateString())->toBe('2026-09-12')
        ->and($range->end()->toDateString())->toBe('2026-09-18');
});

it('compares complete days with the same number of days before them', function (): void {
    $range = StatsRange::make(7, 'daily', $this->today);

    expect(array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $range->currentWindow()))
        ->toBe(['2026-09-12', '2026-09-17'])
        ->and(array_map(fn (CarbonImmutable $day): string => $day->toDateString(), $range->previousWindow()))
        ->toBe(['2026-09-06', '2026-09-11']);
});

it('builds daily buckets with today marked as partial', function (): void {
    $buckets = StatsRange::make(7, 'daily', $this->today)->buckets();

    expect($buckets)->toHaveCount(7)
        ->and([$buckets[0]['key'], $buckets[0]['label'], $buckets[0]['partial']])->toBe(['2026-09-12', 'Sep 12', false])
        ->and([$buckets[6]['key'], $buckets[6]['label'], $buckets[6]['partial']])->toBe(['2026-09-18', 'Sep 18 (so far)', true]);
});

it('builds ISO week buckets with partial first and current weeks', function (): void {
    $buckets = StatsRange::make(30, 'weekly', $this->today)->buckets();

    expect(array_column($buckets, 'key'))->toBe(['2026-08-17', '2026-08-24', '2026-08-31', '2026-09-07', '2026-09-14'])
        ->and(array_column($buckets, 'partial'))->toBe([true, false, false, false, true])
        ->and($buckets[0]['start']->toDateString())->toBe('2026-08-20')
        ->and($buckets[0]['label'])->toBe('Week of Aug 17 (partial)')
        ->and($buckets[1]['label'])->toBe('Week of Aug 24')
        ->and($buckets[4]['end']->toDateString())->toBe('2026-09-18');
});

it('maps a date onto its bucket key', function (): void {
    $wednesday = CarbonImmutable::parse('2026-09-16 22:00:00', 'UTC');

    expect(StatsRange::make(30, 'weekly', $this->today)->bucketKey($wednesday))->toBe('2026-09-14')
        ->and(StatsRange::make(30, 'daily', $this->today)->bucketKey($wednesday))->toBe('2026-09-16');
});

it('keys caches on the days, grain and current day', function (): void {
    expect(StatsRange::make(30, 'daily', $this->today)->cacheKey())->toBe('30:daily:2026-09-18');
});
