<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AltIndicatorType;
use App\Support\DataTransferObjects\AltIndicator;
use Carbon\CarbonImmutable;
use Database\Factories\AltWatchIndicatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $alt_watch_id
 * @property AltIndicatorType $type
 * @property string $value
 * @property string|null $label
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AltWatch $watch
 */
final class AltWatchIndicator extends Model
{
    /** @use HasFactory<AltWatchIndicatorFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AltWatch, $this>
     */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(AltWatch::class, 'alt_watch_id');
    }

    public function toIndicator(): AltIndicator
    {
        return new AltIndicator($this->type, $this->value, $this->label ?? $this->value);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'type' => AltIndicatorType::class,
        ];
    }
}
