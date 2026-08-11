<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * The per IP budget for actions that can reach an archived account
 */
final class ArchivedAccountLookupLimiter
{
    public static function key(string $scope = 'lookup'): string
    {
        return 'archived-account:'.$scope.':'.(request()->ip() ?? 'unknown');
    }

    public static function tooManyAttempts(string $scope = 'lookup'): bool
    {
        return RateLimiter::tooManyAttempts(self::key($scope), config()->integer('recovery.max_attempts', 5));
    }

    public static function hit(string $scope = 'lookup'): void
    {
        RateLimiter::hit(self::key($scope), config()->integer('recovery.decay_seconds', 900));
    }

    public static function availableIn(string $scope = 'lookup'): int
    {
        return RateLimiter::availableIn(self::key($scope));
    }
}
