<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IgnoredUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IgnoredUser>
 */
final class IgnoredUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'ignored_user_id' => User::factory(),
            'include_coauthored' => false,
        ];
    }
}
