<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ModCountryDailyDownload;
use App\Models\ModDailyStat;
use App\Models\ModVersionDailyDownload;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;

/**
 * Deletes mod stats rows past the retention window. The dashboard shows at most 120 days and compares them with the 120
 * days before, so 240 days are kept.
 */
#[Timeout(300)]
#[Tries(1)]
final class PruneModStatsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int RETENTION_DAYS = 240;

    private const int CHUNK = 1000;

    public function handle(): void
    {
        $cutoff = CarbonImmutable::now('UTC')->startOfDay()->subDays(self::RETENTION_DAYS)->toDateString();

        /** @var list<class-string<Model>> $models */
        $models = [ModDailyStat::class, ModVersionDailyDownload::class, ModCountryDailyDownload::class];

        foreach ($models as $model) {
            do {
                $deleted = $model::query()->where('date', '<', $cutoff)->limit(self::CHUNK)->delete();
            } while ($deleted > 0);
        }
    }
}
