<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A kind of value an alt watch can look for. Identifiers can find accounts. Qualifiers only narrow a match, because
 * values such as a browser are shared by thousands of accounts and are not indexed.
 */
enum AltIndicatorType: string
{
    case Device = 'device';

    case Ip = 'ip';

    case IpRange = 'ip_range';

    case EmailDomain = 'email_domain';

    case UserAgent = 'user_agent';

    case BrowserPrint = 'browser_print';

    case Country = 'country';

    public function isIdentifier(): bool
    {
        return match ($this) {
            self::Device, self::Ip, self::IpRange, self::EmailDomain => true,
            self::UserAgent, self::BrowserPrint, self::Country => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Device => 'Device',
            self::Ip => 'IP address',
            self::IpRange => 'IP range',
            self::EmailDomain => 'Email domain',
            self::UserAgent => 'User agent',
            self::BrowserPrint => 'Browser print',
            self::Country => 'Country',
        };
    }
}
