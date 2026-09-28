<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Groups IP addresses into the network an ISP usually hands one customer: a /24 for IPv4 and a /64 for IPv6.
 */
final class IpNetwork
{
    /**
     * The network the address belongs to in CIDR form, or null when it is not a usable address.
     */
    public static function rangeOf(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        // inet_pton() returns raw bytes: without '8bit', mb_* functions read them as UTF-8 and mangle the address.

        if (mb_strlen($packed, '8bit') === 4) {
            $network = inet_ntop(mb_substr($packed, 0, 3, '8bit')."\0");

            return $network === false ? null : $network.'/24';
        }

        // A zero first group (loopback, IPv4-mapped) would put unrelated addresses into one meaningless "::/64".
        if (str_starts_with($packed, "\0\0")) {
            return null;
        }

        $network = inet_ntop(mb_substr($packed, 0, 8, '8bit').str_repeat("\0", 8));

        return $network === false ? null : $network.'/64';
    }

    /**
     * A LIKE prefix every address in the network starts with, to narrow an indexed lookup before rangeOf() confirms
     * each address. For IPv6 it takes the network's four groups, but text drops zero groups, so it stops before the
     * first one.
     */
    public static function likePrefix(string $range): string
    {
        $network = Str::before($range, '/');

        if (str_contains($network, '.')) {
            return Str::beforeLast($network, '.').'.';
        }

        $prefix = '';

        foreach (array_slice(explode(':', $network), 0, 4) as $group) {
            if ($group === '' || $group === '0') {
                break;
            }

            $prefix .= $group.':';
        }

        return $prefix;
    }
}
