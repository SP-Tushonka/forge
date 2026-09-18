<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|null $hub_id
 * @property int $user_id
 * @property string $reactable_type
 * @property int $reactable_id
 * @property int $emoji_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Emoji $emoji
 * @property-read Model $reactable
 */
final class Reaction extends Model
{
    /** @use HasFactory<ReactionFactory> */
    use HasFactory;

    /**
     * The user who left the reaction.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The whitelisted emoji this reaction uses.
     *
     * @return BelongsTo<Emoji, $this>
     */
    public function emoji(): BelongsTo
    {
        return $this->belongsTo(Emoji::class);
    }

    /**
     * The comment or mod being reacted to.
     *
     * @return MorphTo<Model, $this>
     */
    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hub_id' => 'integer',
            'user_id' => 'integer',
            'reactable_id' => 'integer',
            'emoji_id' => 'integer',
        ];
    }
}
