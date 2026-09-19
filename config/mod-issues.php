<?php

declare(strict_types=1);

return [
    'validation' => [
        'title_max' => 120,
        'body_max' => 10000,
    ],

    // Managers and staff are exempt: they log their own work as issues.
    'rate_limiting' => [
        'max_attempts' => 5,
        'decay_seconds' => 3600,
    ],
];
