<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AltWatchMatchMode;
use App\Models\AltWatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AltWatch>
 */
final class AltWatchFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'watched_user_id' => User::factory(),
            'watched_user_name' => function (array $attributes): string {
                $user = User::query()->find($attributes['watched_user_id']);

                return $user instanceof User ? $user->name : 'Deleted user';
            },
            'created_by' => User::factory(),
            'reason' => 'Suspected ban evasion.',
            'match_mode' => AltWatchMatchMode::Any,
            'expires_at' => now()->addDays(90),
        ];
    }

    public function ended(): self
    {
        return $this->state(fn (): array => ['ended_at' => now()->subDay()]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }

    public function matchingAll(): self
    {
        return $this->state(fn (): array => ['match_mode' => AltWatchMatchMode::All]);
    }
}
