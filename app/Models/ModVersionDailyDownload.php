<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ModVersionDailyDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * One mod version's downloads for one UTC day.
 *
 * @property int $id
 * @property int $mod_version_id
 * @property int $mod_id
 * @property string $date UTC day, Y-m-d
 * @property int $downloads
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class ModVersionDailyDownload extends Model
{
    /** @use HasFactory<ModVersionDailyDownloadFactory> */
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
