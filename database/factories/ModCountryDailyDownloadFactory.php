<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mod;
use App\Models\ModCountryDailyDownload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModCountryDailyDownload>
 */
final class ModCountryDailyDownloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mod_id' => Mod::factory(),
            'date' => CarbonImmutable::now('UTC')->toDateString(),
            'country_code' => fake()->countryCode(),
            'downloads' => fake()->numberBetween(1, 200),
        ];
    }
}
