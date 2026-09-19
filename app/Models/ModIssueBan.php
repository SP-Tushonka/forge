<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\PublishedScope;
use Carbon\CarbonImmutable;
use Database\Factories\ModIssueBanFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $mod_id
 * @property int $user_id
 * @property int|null $banned_by
 * @property string|null $reason
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Mod $mod
 * @property-read User $user
 * @property-read User|null $bannedBy
 */
final class ModIssueBan extends Model
{
    /** @use HasFactory<ModIssueBanFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Mod, $this>
     */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class)->withoutGlobalScope(PublishedScope::class);
    }

    /**
     * The banned member.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /**
     * @param  Builder<ModIssueBan>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $q): Builder => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'mod_id' => 'integer',
            'user_id' => 'integer',
            'banned_by' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
