<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Personal Data Retention
    |--------------------------------------------------------------------------
    |
    | Months that incidental personal data is kept before data:prune-personal
    | deletes or scrubs it. Data left behind by deleted accounts is removed
    | on the next daily run regardless. Bans and their audit trail are exempt
    | and kept forever. Accounts and the content people write are not affected.
    |
    */

    'months' => (int) env('DATA_RETENTION_MONTHS', 12),

];
