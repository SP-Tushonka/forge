<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tombstone Key
    |--------------------------------------------------------------------------
    |
    | The application key of the retired Forge, used to pepper email tombstones.
    | This is NOT this applications APP_KEY: the tombstones were derived before
    | the handover and only the original key reproduces them. Lose it and every
    | archived account becomes permanently unrecoverable, leak it and the users
    | table becomes an offline oracle for "did this address have an account"
    |
    */

    'tombstone_key' => env('APP_KEY_TOMBSTONE', ''),

    /*
    |--------------------------------------------------------------------------
    | Recovery Link
    |--------------------------------------------------------------------------
    |
    | The emailed token is stored as a SHA-256 digest, so the window is the only
    | thing standing between a leaked inbox and an archived account. Kept short.
    |
    */

    'token_length' => 64,
    'expiry_minutes' => (int) env('RECOVERY_EXPIRY_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Every lookup costs one bcrypt hash at cost 12 (around 150ms), so an unthrottled
    | endpoint is both a CPU sink and a membership. Limited per address
    | and, at the route, per IP.
    |
    */

    'max_attempts' => (int) env('RECOVERY_MAX_ATTEMPTS', 5),
    'decay_seconds' => (int) env('RECOVERY_DECAY_SECONDS', 900),

];
