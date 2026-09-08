<?php

declare(strict_types=1);

use App\Models\User;

dataset('staff abilities', [
    'changeEmail',
    'removeEmail',
    'sendPasswordReset',
    'invalidatePassword',
    'removeTwoFactor',
    'unlinkDiscord',
    'lockAccount',
    'removePhotos',
]);

it('allows staff to act on a regular user', function (string $ability): void {
    $staff = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect($staff->can($ability, $target))->toBeTrue();
})->with('staff abilities');

it('forbids staff acting on themselves', function (string $ability): void {
    $staff = User::factory()->admin()->create();

    expect($staff->can($ability, $staff))->toBeFalse();
})->with('staff abilities');

it('forbids staff acting on another staff member', function (string $ability): void {
    $staff = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    expect($staff->can($ability, $other))->toBeFalse();
})->with('staff abilities');

it('forbids moderators and senior moderators', function (string $ability): void {
    $target = User::factory()->create();

    expect(User::factory()->moderator()->create()->can($ability, $target))->toBeFalse()
        ->and(User::factory()->seniorModerator()->create()->can($ability, $target))->toBeFalse();
})->with('staff abilities');

it('forbids regular users', function (string $ability): void {
    expect(User::factory()->create()->can($ability, User::factory()->create()))->toBeFalse();
})->with('staff abilities');
