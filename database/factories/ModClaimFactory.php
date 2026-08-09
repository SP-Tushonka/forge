<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClaimVerificationMethod;
use App\Enums\ModClaimStatus;
use App\Models\Mod;
use App\Models\ModClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModClaim>
 */
final class ModClaimFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mod_id' => Mod::factory(),
            'user_id' => User::factory(),
            'token' => Str::random(32),
            'status' => ModClaimStatus::Pending,
            'verified_via' => null,
            'verified_at' => null,
            'escalated_at' => null,
            'expires_at' => now()->addHours(72),
        ];
    }

    /**
     * A verified claim, proven by the given method.
     */
    public function verified(ClaimVerificationMethod $via = ClaimVerificationMethod::GitHub): static
    {
        return $this->state([
            'status' => ModClaimStatus::Verified,
            'verified_via' => $via,
            'verified_at' => now(),
        ]);
    }

    /**
     * A pending claim the user has escalated to the moderation queue.
     */
    public function escalated(): static
    {
        return $this->state([
            'status' => ModClaimStatus::Pending,
            'escalated_at' => now(),
        ]);
    }

    /**
     * A rejected claim.
     */
    public function rejected(): static
    {
        return $this->state(['status' => ModClaimStatus::Rejected]);
    }

    /**
     * An unescalated claim whose token window has passed.
     */
    public function expired(): static
    {
        return $this->state([
            'status' => ModClaimStatus::Expired,
            'expires_at' => now()->subHour(),
        ]);
    }
}
