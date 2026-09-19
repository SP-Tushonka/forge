<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mod;
use App\Models\ModDailyStat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModDailyStat>
 */
final class ModDailyStatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mod_id' => Mod::factory(),
            'date' => CarbonImmutable::now('UTC')->toDateString(),
            'downloads' => fake()->numberBetween(0, 500),
            'views' => fake()->numberBetween(0, 2000),
        ];
    }
}
