<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AltMatchOutcome;
use App\Models\AltWatch;
use App\Models\AltWatchMatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AltWatchMatch>
 */
final class AltWatchMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'alt_watch_id' => AltWatch::factory(),
            'user_id' => User::factory(),
            'matched_indicator_ids' => [],
            'baseline' => false,
            'first_matched_at' => now(),
            'last_matched_at' => now(),
        ];
    }

    public function baseline(): self
    {
        return $this->state(fn (): array => ['baseline' => true]);
    }

    public function reviewed(AltMatchOutcome $outcome = AltMatchOutcome::Dismissed): self
    {
        return $this->state(fn (): array => ['review_outcome' => $outcome, 'reviewed_at' => now()]);
    }
}
