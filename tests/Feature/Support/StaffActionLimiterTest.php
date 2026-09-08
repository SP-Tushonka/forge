<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\StaffActionLimiter;

it('permits staff actions below the budget', function (): void {
    $staff = User::factory()->admin()->create();

    foreach (range(1, 29) as $ignored) {
        StaffActionLimiter::hit($staff);
    }

    expect(StaffActionLimiter::tooManyAttempts($staff))->toBeFalse();
});

it('blocks staff actions once the budget is spent', function (): void {
    $staff = User::factory()->admin()->create();

    foreach (range(1, 30) as $ignored) {
        StaffActionLimiter::hit($staff);
    }

    expect(StaffActionLimiter::tooManyAttempts($staff))->toBeTrue()
        ->and(StaffActionLimiter::availableIn($staff))->toBeGreaterThan(0);
});

it('budgets each staff member separately', function (): void {
    $staff = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    foreach (range(1, 30) as $ignored) {
        StaffActionLimiter::hit($staff);
    }

    expect(StaffActionLimiter::tooManyAttempts($other))->toBeFalse();
});
