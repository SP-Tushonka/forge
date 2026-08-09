<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClaimVerificationMethod;
use App\Enums\ModClaimStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ModClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $mod_id
 * @property int $user_id
 * @property string $token
 * @property ModClaimStatus $status
 * @property ClaimVerificationMethod|null $verified_via
 * @property string|null $verified_url
 * @property CarbonImmutable|null $verified_at
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Mod $mod
 * @property-read User $user
 */
#[Table(name: 'mod_claims')]
final class ModClaim extends Model
{
    /** @use HasFactory<ModClaimFactory> */
    use HasFactory;

    /**
     * The mod being claimed
     *
     * @return BelongsTo<Mod, $this>
     */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class);
    }

    /**
     * The user making the claim
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Whether the token window has passed. Escalated claims never expire, they wait on a moderator 
     * to either reject or approve
     */
    public function isExpired(): bool
    {
        return $this->escalated_at === null
            && $this->expires_at !== null
            && $this->expires_at->isPast();
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
            'status' => ModClaimStatus::class,
            'verified_via' => ClaimVerificationMethod::class,
            'verified_at' => 'datetime',
            'escalated_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
