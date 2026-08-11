<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Licence File
    |--------------------------------------------------------------------------
    |
    | The file a modder commits to their repository root to prove the custom
    | license option. Only its emptiness is judged, so the response is capped
    | well below anything that could exhaust memory. The branches searched come
    | from config/claim.php, which drives the same URL builder.
    |
    */

    'file_name' => 'LICENSE.md',
    'max_response_bytes' => (int) env('CUSTOM_LICENSE_MAX_RESPONSE_BYTES', 65536),

    /*
    |--------------------------------------------------------------------------
    | Repository Fan-out
    |--------------------------------------------------------------------------
    |
    | Every source repository listed on the mod must carry the file, so a single
    | attempt costs up to one request per branch per link. Exceeding this cap is
    | rejected rather than truncated: skipping a link would let an unchecked
    | repository through.
    |
    */

    'max_links' => (int) env('CUSTOM_LICENSE_MAX_LINKS', 4),

    /*
    |--------------------------------------------------------------------------
    | Rate Limit
    |--------------------------------------------------------------------------
    |
    | Caps how many verification attempts a single user can make within the
    | decay window. Mod creation already requires MFA, so this is depth rather
    | than the primary control.
    |
    */

    'max_attempts' => (int) env('CUSTOM_LICENSE_MAX_ATTEMPTS', 10),
    'decay_seconds' => (int) env('CUSTOM_LICENSE_DECAY_SECONDS', 3600),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | The first two are applied to each licence file request. The third caps
    | the whole fan-out, which runs inside the create request and would
    | otherwise be able to hold a worker for links x branches x timeout.
    |
    */

    'connect_timeout' => (int) env('CUSTOM_LICENSE_CONNECT_TIMEOUT', 5),
    'timeout' => (int) env('CUSTOM_LICENSE_TIMEOUT', 15),
    'total_timeout' => (int) env('CUSTOM_LICENSE_TOTAL_TIMEOUT', 30),

];
