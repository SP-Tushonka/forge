<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Addresses that can never receive mail, so nothing should ever be dispatched to them
 */
final class UndeliverableAddress
{
    /** @var list<string> RFC 2606 / RFC 6761 reserved names that can never resolve. */
    private const array RESERVED_TLDS = ['invalid', 'test', 'example', 'localhost'];

    public static function check(?string $email): bool
    {
        if ($email === null || $email === '') {
            return true;
        }

        $at = mb_strrpos($email, '@');
        if ($at === false) {
            return true;
        }

        $domain = mb_strtolower(mb_trim(mb_substr($email, $at + 1), " \t.>"));
        if ($domain === '') {
            return true;
        }

        $tld = mb_substr(mb_strrchr($domain, '.') ?: '.'.$domain, 1);

        return in_array($tld, self::RESERVED_TLDS, true);
    }

    /**
     * Filter a list of addresses down to those that could actually be delivered.
     *
     * @param  list<string>  $emails
     * @return list<string>
     */
    public static function deliverable(array $emails): array
    {
        return array_values(array_filter($emails, static fn (string $e): bool => ! self::check($e)));
    }
}
