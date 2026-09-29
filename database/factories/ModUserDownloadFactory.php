<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Mod;
use App\Models\ModUserDownload;
use App\Models\ModVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/**
 * @extends Factory<ModUserDownload>
 */
final class ModUserDownloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'mod_id' => Mod::factory(),
            'mod_version_id' => null,
            'version' => '1.0.0',
            'downloaded_at' => Date::now()->subDays(random_int(0, 90)),
        ];
    }

    /**
     * A record of the given version having been downloaded.
     */
    public function forVersion(ModVersion $version): self
    {
        return $this->state(fn (array $attributes): array => [
            'mod_id' => $version->mod_id,
            'mod_version_id' => $version->id,
            'version' => $version->version,
        ]);
    }
}
