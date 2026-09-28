<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AltMatchOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\AltWatchMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $alt_watch_id
 * @property int $user_id
 * @property list<int> $matched_indicator_ids
 * @property bool $baseline
 * @property CarbonImmutable $first_matched_at
 * @property CarbonImmutable $last_matched_at
 * @property AltMatchOutcome|null $review_outcome
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AltWatch $watch
 * @property-read User $user
 * @property-read User|null $reviewer
 */
final class AltWatchMatch extends Model
{
    /** @use HasFactory<AltWatchMatchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<AltWatch, $this>
     */
    public function watch(): BelongsTo
    {
        return $this->belongsTo(AltWatch::class, 'alt_watch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Labels of the kinds of indicator this match hit, such as "Device" or "IP range". Needs the watch's indicators.
     *
     * @return list<string>
     */
    public function matchedKinds(): array
    {
        $ids = array_flip($this->matched_indicator_ids);
        $kinds = [];

        foreach ($this->watch->indicators as $indicator) {
            if (isset($ids[$indicator->id])) {
                $kinds[$indicator->type->label()] = true;
            }
        }

        return array_keys($kinds);
    }

    public function review(AltMatchOutcome $outcome, User $reviewer): void
    {
        $this->update([
            'review_outcome' => $outcome,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Matches found after the watch was saved that nobody has reviewed yet.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function unreviewed(Builder $query): Builder
    {
        return $query->where('baseline', false)->whereNull('review_outcome');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'matched_indicator_ids' => 'array',
            'baseline' => 'boolean',
            'first_matched_at' => 'datetime',
            'last_matched_at' => 'datetime',
            'review_outcome' => AltMatchOutcome::class,
            'reviewed_at' => 'datetime',
        ];
    }
}
