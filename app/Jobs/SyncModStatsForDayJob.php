<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ModStatsSource;
use App\Services\CloudflareAnalyticsService;
use App\Services\ModStats\DownloadStatsCollector;
use App\Services\ModStats\ModStatsState;
use App\Services\ModStats\ModStatsWriter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciles one UTC day of mod stats with its sources: page views from Cloudflare and downloads from tracking_events.
 * The sources are independent. Views go first because a Cloudflare failure only skips them; a downloads failure throws
 * so the job retries, and must not cost the views already written.
 */
#[Timeout(300)]
#[Tries(3)]
#[Backoff([10, 60, 300])]
final class SyncModStatsForDayJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Cloudflare keeps 31 days of request analytics; older days have no views to fetch.
     */
    public const int VIEWS_MAX_AGE_DAYS = 30;

    /**
     * Days at least this old should no longer change, so a difference from the stored total is worth a warning.
     */
    private const int DRIFT_MIN_AGE_DAYS = 2;

    private const float DRIFT_THRESHOLD = 0.02;

    /**
     * Bounds the unique lock in case a worker dies mid-job.
     */
    public int $uniqueFor = 3600;

    public function __construct(public readonly string $date) {}

    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(
        DownloadStatsCollector $collector,
        CloudflareAnalyticsService $cloudflare,
        ModStatsWriter $writer,
        ModStatsState $state,
    ): void {
        $day = CarbonImmutable::parse($this->date, 'UTC')->startOfDay();
        $today = CarbonImmutable::now('UTC')->startOfDay();

        $this->syncViews($day, $today, $cloudflare, $writer, $state);
        $this->syncDownloads($day, $today, $collector, $writer, $state);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SyncModStatsForDayJob failed', [
            'date' => $this->date,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function syncViews(CarbonImmutable $day, CarbonImmutable $today, CloudflareAnalyticsService $cloudflare, ModStatsWriter $writer, ModStatsState $state): void
    {
        if ($day->lt($today->subDays(self::VIEWS_MAX_AGE_DAYS))) {
            return;
        }

        // The service has already logged why; unknown views must never be written as zero.
        $views = $cloudflare->modPageViews($day, $day->addDay()->min(CarbonImmutable::now('UTC')));

        if ($views === null) {
            return;
        }

        $views = $writer->knownModsOnly($views);
        $this->warnOnDrift(ModStatsSource::Views, $day, $today, $writer, array_sum($views));

        if ($writer->writeViews($day, $views)) {
            $state->bumpVersion();
        }

        $state->markSynced(ModStatsSource::Views);
    }

    private function syncDownloads(CarbonImmutable $day, CarbonImmutable $today, DownloadStatsCollector $collector, ModStatsWriter $writer, ModStatsState $state): void
    {
        $rows = $collector->forDay($day);
        $this->warnOnDrift(ModStatsSource::Downloads, $day, $today, $writer, array_sum(array_column($rows, 'downloads')));

        if ($writer->writeDownloads($day, $rows)) {
            $state->bumpVersion();
        }

        $state->markSynced(ModStatsSource::Downloads);
    }

    private function warnOnDrift(ModStatsSource $source, CarbonImmutable $day, CarbonImmutable $today, ModStatsWriter $writer, int $fresh): void
    {
        if ($day->gt($today->subDays(self::DRIFT_MIN_AGE_DAYS))) {
            return;
        }

        $stored = $writer->storedTotal($day, $source);

        if ($stored === 0 || abs($fresh - $stored) / $stored <= self::DRIFT_THRESHOLD) {
            return;
        }

        Log::warning('Mod stats drifted on a settled day', [
            'date' => $day->toDateString(),
            'source' => $source->value,
            'stored' => $stored,
            'fresh' => $fresh,
        ]);
    }
}
