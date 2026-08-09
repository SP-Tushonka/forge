<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Censored Words
    |--------------------------------------------------------------------------
    |
    | Comma separated list of words to censor in user generated content (mod
    | names, teasers, descriptions, comments, and chat messages). Matching is
    | case insensitive and detects common character substitutions such as "4"
    | for "A" or "5" for "S", and many othe examples like this. Matches are
    | replaced with their first character followed by asterisks. Standalone
    | words only, words inside URLs and domain names are skipped so posted 
    | links keep working
    |
    */

    'words' => env('CENSOR_WORDS', ''),

];
