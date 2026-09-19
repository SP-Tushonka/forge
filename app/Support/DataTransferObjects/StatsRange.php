<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\StatsGrain;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The period a mod stats page shows: the last N UTC days ending today (today counts as a partial day), bucketed daily
 * or by ISO week. Values from the URL are sanitised here, so a hand-edited query string can't break the page.
 */
final readonly class StatsRange
{
    /**
     * @var list<int>
     */
    public const array ALLOWED_DAYS = [7, 30, 90, 120];

    public const int DEFAULT_DAYS = 30;

    private function __construct(
        public int $days,
        public StatsGrain $grain,
        public CarbonImmutable $today,
    ) {}

    public static function make(mixed $days, mixed $grain, ?CarbonImmutable $today = null): self
    {
        $days = is_numeric($days) && in_array((int) $days, self::ALLOWED_DAYS, true) ? (int) $days : self::DEFAULT_DAYS;
        $grain = is_string($grain) ? (StatsGrain::tryFrom($grain) ?? StatsGrain::Daily) : StatsGrain::Daily;

        // A single week has nothing to bucket.
        if ($days === 7) {
            $grain = StatsGrain::Daily;
        }

        return new self($days, $grain, ($today ?? CarbonImmutable::now('UTC'))->utc()->startOfDay());
    }

    public function start(): CarbonImmutable
    {
        return $this->today->subDays($this->days - 1);
    }

    public function end(): CarbonImmutable
    {
        return $this->today;
    }

    /**
     * The complete days in the range. Today is excluded so a half-finished day never reads as a drop.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function currentWindow(): array
    {
        return [$this->start(), $this->today->subDay()];
    }

    /**
     * The same number of complete days immediately before the range.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function previousWindow(): array
    {
        return [$this->start()->subDays($this->days - 1), $this->start()->subDay()];
    }

    /**
     * @return list<array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable, partial: bool}>
     */
    public function buckets(): array
    {
        $buckets = [];

        if ($this->grain === StatsGrain::Daily) {
            for ($day = $this->start(); $day->lte($this->today); $day = $day->addDay()) {
                $partial = $day->equalTo($this->today);
                $buckets[] = [
                    'key' => $day->toDateString(),
                    'label' => $day->format('M j').($partial ? ' (so far)' : ''),
                    'start' => $day,
                    'end' => $day,
                    'partial' => $partial,
                ];
            }

            return $buckets;
        }

        for ($week = $this->start()->startOfWeek(CarbonInterface::MONDAY); $week->lte($this->today); $week = $week->addWeek()) {
            $start = $week->max($this->start());
            $end = $week->addDays(6)->min($this->today);
            $partial = ! $start->equalTo($week) || ! $end->equalTo($week->addDays(6)) || $end->equalTo($this->today);
            $buckets[] = [
                'key' => $week->toDateString(),
                'label' => 'Week of '.$week->format('M j').($partial ? ' (partial)' : ''),
                'start' => $start,
                'end' => $end,
                'partial' => $partial,
            ];
        }

        return $buckets;
    }

    public function bucketKey(CarbonInterface $date): string
    {
        $day = CarbonImmutable::instance($date)->utc()->startOfDay();

        return $this->grain === StatsGrain::Weekly
            ? $day->startOfWeek(CarbonInterface::MONDAY)->toDateString()
            : $day->toDateString();
    }

    public function cacheKey(): string
    {
        return sprintf('%d:%s:%s', $this->days, $this->grain->value, $this->today->toDateString());
    }
}
