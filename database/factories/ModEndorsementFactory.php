<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<ModEndorsement>
 */
final class ModEndorsementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Spread across a window wide enough that the 7 day, 30 day and all time leaderboards differ locally.
        $endorsedAt = Date::now()->subDays(random_int(0, 90))->subHours(random_int(0, 23));

        return [
            'user_id' => User::factory(),
            'mod_id' => Mod::factory(),
            'endorsed_at' => $endorsedAt,
            'revoked_at' => null,
            'created_at' => $endorsedAt,
            'updated_at' => $endorsedAt,
        ];
    }

    /**
     * An endorsement the user has since withdrawn. The anchor is kept so a re-endorsement reuses it.
     */
    public function revoked(): self
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => Date::now()->subDays(random_int(0, 5)),
        ]);
    }

    /**
     * An endorsement anchored inside the given number of days.
     */
    public function within(int $days): self
    {
        return $this->state(function (array $attributes) use ($days): array {
            $endorsedAt = Date::now()->subDays(random_int(0, max(0, $days - 1)));

            return [
                'endorsed_at' => $endorsedAt,
                'created_at' => $endorsedAt,
                'updated_at' => $endorsedAt,
            ];
        });
    }
}
