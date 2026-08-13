<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Renders the configured recipient pattern into a Postfix regexp transport table.
 */
final readonly class MailRelayTransportMap
{
    private const string HEADER = <<<'TXT'
        # Generated from MAIL_RELAY_DOMAIN_PATTERN by `php artisan mail:sync-relay-transport`.
        # Edits here are overwritten on the next deploy — change the application .env instead.
        TXT;

    public function __construct(
        private string $pattern,
        private string $transport,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            mb_trim(config()->string('mail-relay.pattern')),
            mb_trim(config()->string('mail-relay.transport')),
        );
    }

    /**
     * Whether anything at all is routed away from direct delivery.
     */
    public function isEmpty(): bool
    {
        return mb_trim($this->pattern) === '';
    }

    /**
     * The table, ready to be written. Rendering an empty pattern is legitimate and disables the relay.
     *
     * @throws InvalidArgumentException when the pattern could not appear in a table as a single entry
     */
    public function render(): string
    {
        if ($this->isEmpty()) {
            return self::HEADER."\n# MAIL_RELAY_DOMAIN_PATTERN is unset: every recipient is delivered directly.\n";
        }

        $this->guard($this->pattern, 'MAIL_RELAY_DOMAIN_PATTERN');
        $this->guard($this->transport, 'MAIL_RELAY_TRANSPORT');

        // Anchored both ways so a pattern matches whole domains only, whichever key Postfix looks the
        // recipient up under. The group keeps a top-level alternation from escaping the anchors.
        return self::HEADER."\n".sprintf("/(^|@)(%s)$/\t%s\n", $this->pattern, $this->transport);
    }

    /**
     * A table entry is one line, and the expression is delimited by slashes. Anything carrying either
     * would silently become a different rule than the one that was written.
     */
    private function guard(string $value, string $key): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new InvalidArgumentException($key.' cannot span lines: each map entry is a single line.');
        }

        if (str_contains($value, '/')) {
            throw new InvalidArgumentException($key.' cannot contain "/": it delimits the expression.');
        }
    }
}
