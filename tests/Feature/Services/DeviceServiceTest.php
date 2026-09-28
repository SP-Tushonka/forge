<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

function deviceRequest(?string $token = null): Request
{
    return Request::create('/', 'GET', cookies: $token === null ? [] : [DeviceService::COOKIE => $token], server: [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
    ]);
}

it('issues a token once per request and keeps a valid cookie token', function (): void {
    $service = resolve(DeviceService::class);

    $request = deviceRequest();
    $token = $service->token($request);

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and($service->token($request))->toBe($token)
        ->and($service->token(deviceRequest(str_repeat('a', 43))))->toBe(str_repeat('a', 43))
        ->and($service->token(deviceRequest('forged')))->not->toBe('forged');
});

it('creates a row holding only the hash, then updates it', function (): void {
    $service = resolve(DeviceService::class);
    $user = User::factory()->create();
    $token = str_repeat('b', 43);

    $first = $service->touch($user, deviceRequest($token), interactiveLogin: true);
    $second = $service->touch($user, deviceRequest($token), interactiveLogin: true);

    expect($first->created)->toBeTrue()
        ->and($second->created)->toBeFalse()
        ->and($second->device->id)->toBe($first->device->id)
        ->and($first->device->device_hash)->toBe(hash('sha256', $token))
        ->and($first->device->browser)->toBe('Chrome')
        ->and(UserDevice::query()->where('device_hash', $token)->exists())->toBeFalse()
        ->and(Cookie::hasQueued(DeviceService::COOKIE))->toBeTrue();
});

it('lifts a sign-out only on an interactive login', function (): void {
    $service = resolve(DeviceService::class);
    $token = str_repeat('c', 43);
    $device = UserDevice::factory()->revoked()->create(['device_hash' => hash('sha256', $token)]);

    $remembered = $service->touch($device->user, deviceRequest($token), interactiveLogin: false);

    expect($remembered->device->revoked_at)->not->toBeNull()
        ->and($service->isRevoked($device->user, deviceRequest($token)))->toBeTrue();

    $interactive = $service->touch($device->user, deviceRequest($token), interactiveLogin: true);

    expect($interactive->wasRevoked)->toBeTrue()
        ->and($interactive->device->revoked_at)->toBeNull()
        ->and($service->isRevoked($device->user, deviceRequest($token)))->toBeFalse();
});

it('takes effect immediately when a device is signed out', function (): void {
    $service = resolve(DeviceService::class);
    $token = str_repeat('d', 43);
    $device = UserDevice::factory()->create(['device_hash' => hash('sha256', $token)]);

    expect($service->isRevoked($device->user, deviceRequest($token)))->toBeFalse();

    $service->revoke($device);

    expect($service->isRevoked($device->user, deviceRequest($token)))->toBeTrue();
});

it('signs out every other device but the current one', function (): void {
    $service = resolve(DeviceService::class);
    $user = User::factory()->create();
    $token = str_repeat('e', 43);
    $current = UserDevice::factory()->for($user)->create(['device_hash' => hash('sha256', $token)]);
    $others = UserDevice::factory()->for($user)->count(2)->create();

    expect($service->revokeOthers($user, deviceRequest($token)))->toBe(2)
        ->and($current->fresh()->revoked_at)->toBeNull()
        ->and($others->every(fn (UserDevice $device): bool => $device->fresh()->revoked_at !== null))->toBeTrue();
});
