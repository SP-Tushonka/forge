<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\NewDeviceLoginNotification;
use App\Services\DeviceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('stays silent for the first device of an account', function (): void {
    $user = User::factory()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    expect($user->devices()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('emails the member when a password login comes from a new device', function (): void {
    $user = User::factory()->create();
    UserDevice::factory()->for($user)->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertCookie(DeviceService::COOKIE);

    Notification::assertSentTo($user, NewDeviceLoginNotification::class,
        fn (NewDeviceLoginNotification $notification): bool => $notification->ip === '127.0.0.1');
});

it('stays silent on a known device', function (): void {
    $user = User::factory()->create();
    $token = str_repeat('i', 43);
    UserDevice::factory()->for($user)->create(['device_hash' => hash('sha256', $token)]);
    UserDevice::factory()->for($user)->create();

    $this->withCookie(DeviceService::COOKIE, $token)
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
    Notification::assertNothingSent();
});

it('emails the member when a signed-out device logs back in', function (): void {
    $user = User::factory()->create();
    $token = str_repeat('j', 43);
    $device = UserDevice::factory()->for($user)->revoked()->create(['device_hash' => hash('sha256', $token)]);

    $this->withCookie(DeviceService::COOKIE, $token)
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    expect($device->fresh()->revoked_at)->toBeNull();
    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
});

it('covers logins that bypass the password form, such as Discord and account recovery', function (): void {
    $user = User::factory()->create();
    UserDevice::factory()->for($user)->create();

    Auth::login($user);

    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
});

it('never lets a remember-me cookie bring a signed-out device back', function (): void {
    $user = User::factory()->create(['remember_token' => 'remember-token-value']);
    $token = str_repeat('k', 43);
    $device = UserDevice::factory()->for($user)->revoked()->create(['device_hash' => hash('sha256', $token)]);
    $recaller = Auth::guard('web')->getRecallerName();

    $this->withCookie(DeviceService::COOKIE, $token)
        ->withCookie($recaller, $user->id.'|remember-token-value|'.$user->getAuthPassword())
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($device->fresh()->revoked_at)->not->toBeNull();
    Notification::assertNothingSent();
});

it('emails the member when a remember-me cookie signs in from a browser new to the account', function (): void {
    $user = User::factory()->create(['remember_token' => 'remember-token-value']);
    UserDevice::factory()->for($user)->create();
    $recaller = Auth::guard('web')->getRecallerName();

    $this->withCookie($recaller, $user->id.'|remember-token-value|'.$user->getAuthPassword())
        ->get(route('dashboard'))
        ->assertOk();

    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, NewDeviceLoginNotification::class);
});

it('describes the device by its browser and system, never by its nickname', function (): void {
    $user = User::factory()->create();
    $token = str_repeat('l', 43);
    UserDevice::factory()->for($user)->revoked()->create([
        'device_hash' => hash('sha256', $token),
        'name' => '[Verify your account](https://example.com)',
    ]);

    $this->withCookie(DeviceService::COOKIE, $token)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36')
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    Notification::assertSentTo($user, NewDeviceLoginNotification::class,
        fn (NewDeviceLoginNotification $notification): bool => $notification->device === 'Chrome on Windows');
});

it('names the device, location and IP in the email', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Berlin']);
    $signedInAt = now()->toImmutable();
    $notification = new NewDeviceLoginNotification('Firefox on Linux', 'Berlin, DE', '203.0.113.5', $signedInAt);

    $rendered = (string) $notification->toMail($user)->render();

    expect($rendered)->toContain('Firefox on Linux')
        ->toContain('Berlin, DE')
        ->toContain('203.0.113.5')
        ->toContain($signedInAt->setTimezone('Europe/Berlin')->format('F j, Y \a\t g:i A T'));
});
