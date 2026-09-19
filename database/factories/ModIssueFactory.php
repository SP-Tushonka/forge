<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ModIssue>
 */
final class ModIssueFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mod_id' => Mod::factory(),
            'user_id' => User::factory(),
            'type' => ModIssueType::Bug,
            'status' => ModIssueStatus::New,
            'title' => Str::limit(fake()->sentence(), 100, ''),
            'body' => fake()->paragraphs(2, true),
            'last_activity_at' => now(),
        ];
    }

    public function feature(): static
    {
        return $this->state(fn (array $attributes): array => ['type' => ModIssueType::Feature]);
    }

    public function status(ModIssueStatus $status): static
    {
        return $this->state(fn (array $attributes): array => ['status' => $status]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes): array => ['locked_at' => now()]);
    }
}
