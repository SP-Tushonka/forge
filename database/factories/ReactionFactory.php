<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Emoji;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<Reaction>
 */
final class ReactionFactory extends Factory
{
    /**
     * reactable_type and reactable_id are intentionally absent: callers always set them through for() or by creating
     * the reaction through the relation.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $createdAt = Date::now()->subDays(random_int(0, 30))->subHours(random_int(0, 23));

        return [
            'user_id' => User::factory(),
            'emoji_id' => fn (): int => Emoji::query()->where('shortcode', 'heart')->sole()->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }
}
