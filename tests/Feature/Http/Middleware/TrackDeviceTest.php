<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceService;
use Illuminate\Support\Facades\Notification;

it('leaves guests alone', function (): void {
    $this->get('/')->assertCookieMissing(DeviceService::COOKIE);

    expect(UserDevice::query()->count())->toBe(0);
});

it('silently records the device of a session that predates device tracking', function (): void {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertCookie(DeviceService::COOKIE)
        ->assertSessionHas(DeviceService::SESSION_KEY);

    expect($user->devices()->count())->toBe(1);
    Notification::assertNothingSent();
});

it('signs out a device the user has signed out, and only that device', function (): void {
    $user = User::factory()->create();
    $revokedToken = str_repeat('f', 43);
    $activeToken = str_repeat('g', 43);
    UserDevice::factory()->for($user)->revoked()->create(['device_hash' => hash('sha256', $revokedToken)]);
    UserDevice::factory()->for($user)->create(['device_hash' => hash('sha256', $activeToken)]);

    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $activeToken)->get(route('dashboard'))->assertOk();
    $this->flushSession();

    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $revokedToken)->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

it('signs out a session that arrives with a different device cookie than the one it was bound to', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession([DeviceService::SESSION_KEY => hash('sha256', str_repeat('m', 43))])
        ->withCookie(DeviceService::COOKIE, str_repeat('n', 43))
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->devices()->count())->toBe(0);
});

it('returns 419 to Livewire requests with a revoked device', function (): void {
    $user = User::factory()->create();
    $revokedToken = str_repeat('f', 43);
    UserDevice::factory()->for($user)->revoked()->create(['device_hash' => hash('sha256', $revokedToken)]);

    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $revokedToken)->get(route('dashboard'), ['X-Livewire' => 'true'])
        ->assertStatus(419);

    $this->assertGuest();
});

it('writes the device row at most once every five minutes', function (): void {
    $user = User::factory()->create();
    $token = str_repeat('h', 43);

    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $token)->get(route('dashboard'));
    $firstSeen = $user->devices()->sole()->last_seen_at;

    $this->travel(1)->minutes();
    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $token)->get(route('dashboard'));

    expect($user->devices()->sole()->last_seen_at->equalTo($firstSeen))->toBeTrue();

    $this->travel(5)->minutes();
    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $token)->get(route('dashboard'));

    expect($user->devices()->sole()->last_seen_at->greaterThan($firstSeen))->toBeTrue();
});
