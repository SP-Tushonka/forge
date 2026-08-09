<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic Verification Hosts
    |--------------------------------------------------------------------------
    |
    | Essentially the hosts that would be detected as "automatic" for the claim
    |
    */

    'auto_hosts' => [
        'github.com',
        'gitlab.com',
        'codeberg.org',
    ],

    /*
    |--------------------------------------------------------------------------
    | Claim File
    |--------------------------------------------------------------------------
    |
    | The file a claimant commits to their repository root, and the branches
    | searched for it in order. The response is capped well above a token and
    | well below anything that could exhaust memory.
    |
    */

    'file_name' => 'claim.txt',
    'branches' => ['main', 'master'],
    'max_response_bytes' => (int) env('CLAIM_MAX_RESPONSE_BYTES', 512),

    /*
    |--------------------------------------------------------------------------
    | Repository Fan-out
    |--------------------------------------------------------------------------
    |
    | A mod may list several source repositories and the token proves ownership
    | of any one of them, so a single attempt costs one request per branch per
    | allowlisted link. This caps how many links are tried before the remainder
    | is left to manual review.
    |
    */

    'max_links' => (int) env('CLAIM_MAX_LINKS', 5),

    /*
    |--------------------------------------------------------------------------
    | Token
    |--------------------------------------------------------------------------
    |
    | Length of the generated claim token, and how long a claim may go unproven.
    | Escalated claims are exempt from expiry: they are waiting on a moderator,
    | not on the claimant.
    |
    */

    'token_length' => 32,
    'expiry_hours' => (int) env('CLAIM_EXPIRY_HOURS', 72),

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Initiating a claim and retrying verification are limited separately: a
    | retry fans out to one request per branch per linked repository, so it is
    | the cheaper action to abuse. Staff are exempt from both.
    |
    */

    'max_attempts' => (int) env('CLAIM_MAX_ATTEMPTS', 5),
    'decay_seconds' => (int) env('CLAIM_DECAY_SECONDS', 3600),
    'retry_max_attempts' => (int) env('CLAIM_RETRY_MAX_ATTEMPTS', 10),
    'retry_decay_seconds' => (int) env('CLAIM_RETRY_DECAY_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Applied to each claim file request.
    |
    */

    'connect_timeout' => (int) env('CLAIM_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('CLAIM_TIMEOUT', 15),

];
