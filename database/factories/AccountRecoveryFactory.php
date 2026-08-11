<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountRecovery;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountRecovery>
 */
final class AccountRecoveryFactory extends Factory
{
    protected $model = AccountRecovery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addHour(),
            'consumed_at' => null,
        ];
    }

    /**
     * A link whose window has closed.
     */
    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    /**
     * A link that has already handed the account back.
     */
    public function consumed(): self
    {
        return $this->state(fn (): array => ['consumed_at' => now()->subMinute()]);
    }
}
