<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ModDailyStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One mod's download and page-view totals for one UTC day.
 *
 * @property int $id
 * @property int $mod_id
 * @property string $date UTC day, Y-m-d
 * @property int $downloads
 * @property int $views
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ModDailyStat extends Model
{
    /** @use HasFactory<ModDailyStatFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'downloads' => 'integer',
            'views' => 'integer',
        ];
    }
}
