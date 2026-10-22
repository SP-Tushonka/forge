<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Throttles ignore and unignore clicks against spam. Staff are exempt.
 *
 * @phpstan-ignore trait.unused
 */
trait ThrottlesIgnoreToggles
{
    protected function withinIgnoreRateLimit(User $user): bool
    {
        if ($user->isModOrAdmin()) {
            return true;
        }

        $key = 'content-ignore:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 20)) {
            Flux::toast(
                heading: __('Slow down'),
                text: __('You are changing ignores too quickly. Please wait :seconds seconds and try again.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
                variant: 'warning',
            );

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
