<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDevice;

it('labels a device by its nickname, falling back to browser and system', function (): void {
    $device = UserDevice::factory()->make(['name' => null, 'browser' => 'Firefox', 'platform' => 'Linux']);

    expect($device->label())->toBe('Firefox on Linux');

    $device->name = 'Work laptop';

    expect($device->label())->toBe('Work laptop')
        ->and(UserDevice::describe(null, null))->toBe('Unknown browser on unknown system');
});

it('formats the location from whatever parts are known', function (): void {
    expect(UserDevice::factory()->make(['city_name' => 'Berlin', 'country_code' => 'DE'])->location())->toBe('Berlin, DE')
        ->and(UserDevice::factory()->make(['city_name' => null, 'country_code' => null])->location())->toBeNull();
});

it('is deleted with its account', function (): void {
    $device = UserDevice::factory()->create();

    User::query()->findOrFail($device->user_id)->delete();

    expect(UserDevice::query()->find($device->id))->toBeNull();
});
