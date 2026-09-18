<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reactions Configuration
    |--------------------------------------------------------------------------
    |
    | The rate limit guards the reaction buttons, which are a single click and
    | therefore trivially spammable. Staff are exempt from it. The allowance is
    | higher than the endorsements limiter because one page view legitimately
    | involves reacting to several comments.
    |
    */

    'rate_limiting' => [
        'max_attempts' => (int) env('REACTIONS_MAX_ATTEMPTS', 30),
        'duration_seconds' => (int) env('REACTIONS_RATE_DURATION', 60),
    ],
];
