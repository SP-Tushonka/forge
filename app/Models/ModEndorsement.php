<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ModEndorsementFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property int $mod_id
 * @property CarbonImmutable $endorsed_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Mod $mod
 */
final class ModEndorsement extends Model
{
    /** @use HasFactory<ModEndorsementFactory> */
    use HasFactory;

    /**
     * The relationship between an endorsement and the user who gave it.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The relationship between an endorsement and the mod it was given to.
     *
     * @return BelongsTo<Mod, $this>
     */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class);
    }

    /**
     * Limit the query to endorsements that currently stand. Withdrawn endorsements keep their row so that their
     * endorsed_at anchor survives, so every count has to filter on this.
     *
     * @param  Builder<ModEndorsement>  $query
     * @return Builder<ModEndorsement>
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'mod_id' => 'integer',
            'endorsed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
