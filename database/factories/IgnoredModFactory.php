<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IgnoredMod;
use App\Models\Mod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IgnoredMod>
 */
final class IgnoredModFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mod_id' => Mod::factory(),
        ];
    }
}
