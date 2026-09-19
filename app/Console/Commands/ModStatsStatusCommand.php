<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ModStatsSource;
use App\Services\ModStats\ModStatsState;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Report how long ago each mod stats source last synced; exits non-zero when one is stale')]
#[Signature('app:mod-stats-status {--max-age=120 : Minutes after which a source counts as stale}')]
final class ModStatsStatusCommand extends Command
{
    public function handle(ModStatsState $state): int
    {
        $maxAge = $this->option('max-age');
        $maxAge = is_numeric($maxAge) ? (int) $maxAge : 120;
        $now = CarbonImmutable::now('UTC');
        $healthy = true;

        foreach (ModStatsSource::cases() as $source) {
            $synced = $state->lastSynced($source);

            if (! $synced instanceof CarbonImmutable) {
                $this->line(sprintf('%s: never synced', $source->value));
                $healthy = false;

                continue;
            }

            $minutes = (int) $synced->diffInMinutes($now);
            $this->line(sprintf('%s: last synced %d minute(s) ago', $source->value, $minutes));
            $healthy = $healthy && $minutes <= $maxAge;
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
