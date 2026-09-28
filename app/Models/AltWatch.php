<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AltWatchMatchMode;
use Carbon\CarbonImmutable;
use Database\Factories\AltWatchFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property int $id
 * @property int|null $watched_user_id
 * @property string $watched_user_name
 * @property int|null $created_by
 * @property string $reason
 * @property AltWatchMatchMode $match_mode
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $ended_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $watchedUser
 * @property-read User|null $creator
 * @property-read User|null $ender
 * @property-read Collection<int, AltWatchIndicator> $indicators
 * @property-read Collection<int, AltWatchMatch> $matches
 */
final class AltWatch extends Model
{
    /** @use HasFactory<AltWatchFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function watchedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'watched_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    /**
     * @return HasMany<AltWatchIndicator, $this>
     */
    public function indicators(): HasMany
    {
        return $this->hasMany(AltWatchIndicator::class);
    }

    /**
     * @return HasMany<AltWatchMatch, $this>
     */
    public function matches(): HasMany
    {
        return $this->hasMany(AltWatchMatch::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return 'active'|'ended'|'expired'
     */
    public function status(): string
    {
        return match (true) {
            $this->ended_at !== null => 'ended',
            ! $this->expires_at->isFuture() => 'expired',
            default => 'active',
        };
    }

    public function statusColor(): string
    {
        return match ($this->status()) {
            'active' => 'green',
            'ended' => 'zinc',
            'expired' => 'amber',
        };
    }

    /**
     * The watched account's name, falling back to the name saved on the watch once the account is deleted.
     */
    public function watchedName(): string
    {
        return $this->watchedUser->name ?? $this->watched_user_name;
    }

    public function end(User $actor): void
    {
        $this->update(['ended_at' => now(), 'ended_by' => $actor->id]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'match_mode' => AltWatchMatchMode::class,
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
