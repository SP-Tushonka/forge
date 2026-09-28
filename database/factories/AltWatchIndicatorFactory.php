<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AltIndicatorType;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AltWatchIndicator>
 */
final class AltWatchIndicatorFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'alt_watch_id' => AltWatch::factory(),
            'type' => AltIndicatorType::Ip,
            'value' => '203.0.113.10',
            'label' => null,
        ];
    }
}
