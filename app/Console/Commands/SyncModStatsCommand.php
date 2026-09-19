<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TrackingEventType;
use App\Jobs\PruneModStatsJob;
use App\Jobs\SyncModStatsForDayJob;
use App\Models\TrackingEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Queue mod stats reconciliation for a range of UTC days')]
#[Signature('app:sync-mod-stats
    {--from=1 : The oldest day to sync, in days before today}
    {--to=0 : The newest day to sync, in days before today}
    {--backfill : Start from the first recorded download (bounded by the retention window) instead of --from}')]
final class SyncModStatsCommand extends Command
{
    public function handle(): int
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $to = $this->daysOption('to');
        $from = $this->option('backfill') === true ? $this->backfillFrom($today) : $this->daysOption('from');

        if ($to > $from) {
            $this->error('--to must not be further back than --from.');

            return self::FAILURE;
        }

        for ($daysAgo = $from; $daysAgo >= $to; $daysAgo--) {
            dispatch(new SyncModStatsForDayJob($today->subDays($daysAgo)->toDateString()));
        }

        $this->info(sprintf('Queued %d day(s) of mod stats reconciliation.', $from - $to + 1));

        return self::SUCCESS;
    }

    private function daysOption(string $name): int
    {
        $value = $this->option($name);

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Days back to the first recorded download: at least Cloudflare's views window, at most the retention window.
     */
    private function backfillFrom(CarbonImmutable $today): int
    {
        $first = TrackingEvent::query()->where('event_name', TrackingEventType::MOD_DOWNLOAD->value)->min('created_at');
        $days = is_string($first) ? (int) CarbonImmutable::parse($first, 'UTC')->startOfDay()->diffInDays($today) : 0;

        return min(max($days, SyncModStatsForDayJob::VIEWS_MAX_AGE_DAYS), PruneModStatsJob::RETENTION_DAYS);
    }
}
