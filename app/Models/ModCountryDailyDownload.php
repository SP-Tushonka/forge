<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ModCountryDailyDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One mod's downloads from one country for one UTC day.
 *
 * @property int $id
 * @property int $mod_id
 * @property string $date UTC day, Y-m-d
 * @property string $country_code
 * @property int $downloads
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ModCountryDailyDownload extends Model
{
    /** @use HasFactory<ModCountryDailyDownloadFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'downloads' => 'integer',
        ];
    }
}
