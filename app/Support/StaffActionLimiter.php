<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-staff-member budget for destructive account actions, so a compromised
 * staff session cannot enumerate users and mass-wipe accounts.
 */
final class StaffActionLimiter
{
    private const int MAX_ATTEMPTS = 30;

    private const int DECAY_SECONDS = 900;

    public static function key(User $staff): string
    {
        return 'staff-action:'.$staff->id;
    }

    public static function tooManyAttempts(User $staff): bool
    {
        return RateLimiter::tooManyAttempts(self::key($staff), self::MAX_ATTEMPTS);
    }

    public static function hit(User $staff): void
    {
        RateLimiter::hit(self::key($staff), self::DECAY_SECONDS);
    }

    public static function availableIn(User $staff): int
    {
        return RateLimiter::availableIn(self::key($staff));
    }
}
