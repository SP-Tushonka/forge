<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ModVersion;
use App\Models\ModVersionDailyDownload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModVersionDailyDownload>
 */
final class ModVersionDailyDownloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mod_version_id' => ModVersion::factory(),
            'mod_id' => fn (array $attributes): mixed => ModVersion::query()
                ->withoutGlobalScopes()
                ->whereKey($attributes['mod_version_id'])
                ->value('mod_id'),
            'date' => CarbonImmutable::now('UTC')->toDateString(),
            'downloads' => fake()->numberBetween(0, 500),
        ];
    }
}
