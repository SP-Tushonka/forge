<?php

declare(strict_types=1);

use App\Support\MailRelayTransportMap;

describe('MailRelayTransportMap', function (): void {
    it('anchors the pattern against the domain and the full address', function (): void {
        $table = new MailRelayTransportMap('gmx\.(de|net)', 'gmailrelay:[smtp.gmail.com]:587')->render();

        expect($table)->toContain('/(^|@)(gmx\.(de|net))$/'."\t".'gmailrelay:[smtp.gmail.com]:587');
    });

    it('renders a single entry however many domains the alternation carries', function (): void {
        $table = new MailRelayTransportMap('proton\.me|pm\.me|gmx\..+', 'relay:[host]:587')->render();

        $entries = array_values(array_filter(
            explode("\n", mb_trim($table)),
            static fn (string $line): bool => ! str_starts_with($line, '#'),
        ));

        expect($entries)->toHaveCount(1);
    });

    it('disables the relay on an empty pattern rather than matching everything', function (string $pattern): void {
        $map = new MailRelayTransportMap($pattern, 'relay:[host]:587');

        expect($map->isEmpty())->toBeTrue()
            ->and($map->render())->not->toContain('relay:[host]:587');
    })->with(['empty' => [''], 'whitespace' => ['   ']]);

    it('refuses input that would become a different rule than the one written', function (string $pattern, string $expected): void {
        expect(fn (): string => new MailRelayTransportMap($pattern, 'relay:[host]:587')->render())
            ->toThrow(InvalidArgumentException::class, $expected);
    })->with([
        // Would close the expression early and leave the rest as a second field.
        'slash' => ['gmx\.de/', 'cannot contain "/"'],
        // Would append an unanchored second entry to the table.
        'newline' => ["gmx\\.de\n/.*/\tdiscard:", 'cannot span lines'],
    ]);

    it('reads the configured pattern and transport', function (): void {
        config()->set('mail-relay.pattern', 'web\.de');
        config()->set('mail-relay.transport', 'gmailrelay:[smtp.gmail.com]:587');

        expect(MailRelayTransportMap::fromConfig()->render())
            ->toContain('/(^|@)(web\.de)$/');
    });
});
