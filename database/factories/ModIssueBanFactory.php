<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mod;
use App\Models\ModIssueBan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModIssueBan>
 */
final class ModIssueBanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mod_id' => Mod::factory(),
            'user_id' => User::factory(),
            'banned_by' => User::factory(),
            'reason' => null,
            'expires_at' => null,
        ];
    }
}
