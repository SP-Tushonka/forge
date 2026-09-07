<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

it('removes two factor through the tool', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->withMfa()->create();

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->call('confirm', 'removeTwoFactor')
        ->set('reason', 'User lost their phone')
        ->call('runAction')
        ->assertHasNoErrors();

    expect($target->fresh()->two_factor_secret)->toBeNull();

    Notification::assertSentTo($target, StaffAccountActionNotification::class);
});

it('requires a reason', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->withMfa()->create();

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->call('confirm', 'removeTwoFactor')
        ->set('reason', '')
        ->call('runAction')
        ->assertHasErrors(['reason' => 'required']);
});

it('rejects a reason over the column limit', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->withMfa()->create();

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->call('confirm', 'removeTwoFactor')
        ->set('reason', str_repeat('a', 1001))
        ->call('runAction')
        ->assertHasErrors(['reason' => 'max']);
});

it('changes an email through the tool', function (): void {
    Notification::fake();

    $staff = User::factory()->admin()->create();
    $target = User::factory()->create(['email' => 'old@example.com']);

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->call('confirm', 'changeEmail')
        ->set('newEmail', 'new@example.com')
        ->set('reason', 'Support request')
        ->call('runAction')
        ->assertHasNoErrors();

    expect($target->fresh()->email)->toBe('new@example.com');
});

it('surfaces a refusal as an error instead of a success', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->call('confirm', 'removeTwoFactor')
        ->set('reason', 'nothing to remove')
        ->call('runAction')
        ->assertHasErrors('action');
});

it('forbids acting on another staff member', function (): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->admin()->create();

    Livewire::actingAs($staff)
        ->test('admin.staff-tools.user-tool', ['userId' => $target->id])
        ->call('confirm', 'removeTwoFactor')
        ->set('reason', 'nope')
        ->call('runAction')
        ->assertForbidden();
});
