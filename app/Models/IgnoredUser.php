<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\IgnoredUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * A user whose mods, addons and lists another user has chosen to hide. include_coauthored widens it from the mods and
 * addons they own to the ones they co-author.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ignored_user_id
 * @property bool $include_coauthored
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read User $ignoredUser
 */
final class IgnoredUser extends Model
{
    /** @use HasFactory<IgnoredUserFactory> */
    use HasFactory;

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
    public function ignoredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ignored_user_id');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'include_coauthored' => 'boolean',
        ];
    }
}
