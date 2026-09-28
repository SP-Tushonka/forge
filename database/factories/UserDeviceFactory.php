<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserDevice>
 */
final class UserDeviceFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'device_hash' => hash('sha256', Str::random(43)),
            'browser' => 'Chrome',
            'platform' => 'Windows',
            'device_type' => 'desktop',
            'useragent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
            'last_ip' => '203.0.113.10',
            'country_code' => 'DE',
            'city_name' => 'Berlin',
            'first_seen_at' => now()->subDays(10),
            'last_seen_at' => now()->subHour(),
        ];
    }

    public function revoked(): self
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subMinute()]);
    }
}
