<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Relayed Recipient Domains
    |--------------------------------------------------------------------------
    |
    | One POSIX extended regular expression. Recipients it matches are handed to
    | the authenticated relay instead of being delivered directly, because those
    | operators reject mail from our own IP while its reputation is still young.
    |
    | The expression is wrapped in `(^|@)(...)$` when rendered, so it is matched
    | against the bare recipient domain and against the full address, and only
    | ever against a whole one. Alternation is the point: a single `gmx\.(de|at
    | |...)` covers a family of domains that would otherwise be a line each.
    |
    | This is NOT read when sending. Laravel hands every message to the local
    | MTA, which returns 250 long before the remote refusal arrives, so the
    | decision cannot live in application code. Deploying renders this into the
    | MTA's own table; see `mail:sync-relay-transport`.
    |
    */

    'pattern' => env('MAIL_RELAY_DOMAIN_PATTERN', ''),

    /*
    |--------------------------------------------------------------------------
    | Relay Transport
    |--------------------------------------------------------------------------
    |
    | The right-hand side of every generated map entry, naming the transport
    | defined in master.cf and the smart host it authenticates against.
    |
    */

    'transport' => env('MAIL_RELAY_TRANSPORT', 'gmailrelay:[smtp.gmail.com]:587'),

    'map_path' => env('MAIL_RELAY_MAP_PATH', '/etc/postfix/transport_regexp'),

    /*
    |--------------------------------------------------------------------------
    | Breadth Guard
    |--------------------------------------------------------------------------
    |
    | Addresses the pattern must never match, checked against the rendered table
    | before it is installed. The relay account is a free mailbox capped near 500
    | recipients a day: a pattern broad enough to catch ordinary mail exhausts
    | that in one run and the provider then refuses everything for 24 hours. A
    | stray `.*` is a plausible edit, so it fails the deploy instead.
    |
    */

    'never_relay' => [
        'canary@gmail.com',
        'canary@sp-mod.com',
        'canary@hotmail.com',
        'canary@outlook.com',
    ],

];
