<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceService;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

it('lists active devices, newest first, and hides signed-out ones', function (): void {
    $user = User::factory()->create();
    UserDevice::factory()->for($user)->create(['name' => 'Old laptop', 'last_seen_at' => now()->subDays(3)]);
    UserDevice::factory()->for($user)->create(['name' => 'Phone', 'last_seen_at' => now()->subMinute()]);
    UserDevice::factory()->for($user)->revoked()->create(['name' => 'Gone']);

    $this->actingAs($user);

    Livewire::test('profile.devices')
        ->assertSeeInOrder(['Phone', 'Old laptop'])
        ->assertDontSee('Gone');
});

it('renames a device and clears the name back to the automatic label', function (): void {
    $user = User::factory()->create();
    $device = UserDevice::factory()->for($user)->create(['browser' => 'Firefox', 'platform' => 'Linux']);

    $this->actingAs($user);

    Livewire::test('profile.devices')
        ->call('startRename', $device->id)
        ->set('name', '  Work laptop  ')
        ->call('saveName')
        ->assertHasNoErrors();

    expect($device->fresh()->name)->toBe('Work laptop');

    Livewire::test('profile.devices')
        ->call('startRename', $device->id)
        ->set('name', str_repeat('x', 51))
        ->call('saveName')
        ->assertHasErrors(['name' => 'max']);

    Livewire::test('profile.devices')
        ->call('startRename', $device->id)
        ->set('name', '')
        ->call('saveName');

    expect($device->fresh()->label())->toBe('Firefox on Linux');
});

it('signs out another device', function (): void {
    $user = User::factory()->create();
    $device = UserDevice::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test('profile.devices')->call('signOut', $device->id);

    expect($device->fresh()->revoked_at)->not->toBeNull();
});

it('refuses to act on another member\'s device', function (): void {
    $device = UserDevice::factory()->create();

    $this->actingAs(User::factory()->create());

    Livewire::test('profile.devices')
        ->call('signOut', $device->id)
        ->assertForbidden();

    expect($device->fresh()->revoked_at)->toBeNull();
});

it('asks for the password before signing out all other devices', function (): void {
    $user = User::factory()->create();
    $devices = UserDevice::factory()->for($user)->count(2)->create();

    $this->actingAs($user);

    Livewire::test('profile.devices')
        ->set('password', 'wrong')
        ->call('signOutOthers')
        ->assertHasErrors('password');

    expect($devices->every(fn (UserDevice $device): bool => $device->fresh()->revoked_at === null))->toBeTrue();

    $passwordHash = $user->password;

    Livewire::test('profile.devices')
        ->set('password', 'password')
        ->call('signOutOthers')
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect($devices->every(fn (UserDevice $device): bool => $device->fresh()->revoked_at !== null))->toBeTrue()
        ->and($user->fresh()->password)->not->toBe($passwordHash)
        ->and(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('lets a member without a password sign out other devices', function (): void {
    $user = User::factory()->create(['password' => null]);
    $device = UserDevice::factory()->for($user)->create();

    $this->actingAs($user);

    Livewire::test('profile.devices')->call('signOutOthers')->assertHasNoErrors();

    expect($device->fresh()->revoked_at)->not->toBeNull();
});

it('marks the device in use and offers no sign-out for it', function (): void {
    $user = User::factory()->create();
    $token = str_repeat('c', 43);
    $current = UserDevice::factory()->for($user)->create(['device_hash' => hash('sha256', $token)]);
    $other = UserDevice::factory()->for($user)->create();

    $this->actingAs($user)->withCookie(DeviceService::COOKIE, $token)->get(route('profile.show'))
        ->assertOk()
        ->assertSee('This device')
        ->assertSee(sprintf('signOut(%d)', $other->id), false)
        ->assertDontSee(sprintf('signOut(%d)', $current->id), false);
});

it('never signs out the device in use', function (): void {
    $user = User::factory()->create();
    $token = str_repeat('c', 43);
    $device = UserDevice::factory()->for($user)->create(['device_hash' => hash('sha256', $token)]);

    $this->actingAs($user);

    Livewire::withCookie(DeviceService::COOKIE, $token)
        ->test('profile.devices')
        ->call('signOut', $device->id);

    expect($device->fresh()->revoked_at)->toBeNull();
});
