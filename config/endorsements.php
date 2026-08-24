<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Endorsements Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for mod endorsements. The rate limit guards the toggle button,
    | which is a single click and therefore trivially spammable. Staff are
    | exempt from it.
    |
    */

    'rate_limiting' => [
        'max_attempts' => (int) env('ENDORSEMENTS_MAX_ATTEMPTS', 20),
        'duration_seconds' => (int) env('ENDORSEMENTS_RATE_DURATION', 60),
    ],
];
