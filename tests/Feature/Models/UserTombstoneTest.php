<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The digest phpunit.xml's fixed APP_KEY_TOMBSTONE produces for known@example.com.
 *
 * Every archived account's tombstone was derived with this exact algorithm and can never be re-derived: change the
 * work factor or the salt context and every one of them becomes permanently unrecoverable in production, silently.
 */
const KNOWN_ADDRESS_TOMBSTONE = '$2y$12$amm4qjFmnKqPA5i2Dxajveq1FGmCaBd8baZzdWyTepef3JbaVZsKe';

it('reproduces the pinned digest for a known address', function (): void {
    expect(User::emailTombstoneFor('known@example.com'))
        ->toBe(KNOWN_ADDRESS_TOMBSTONE)
        ->toStartWith('$2y$12$');
});

it('reaches the pinned digest from case and whitespace variants', function (string $variant): void {
    expect(User::emailTombstoneFor($variant))->toBe(KNOWN_ADDRESS_TOMBSTONE);
})->with([
    'KNOWN@Example.COM',
    '  known@example.com  ',
]);

it('produces a different digest for a different address', function (): void {
    expect(User::emailTombstoneFor('other@example.com'))->not->toBe(KNOWN_ADDRESS_TOMBSTONE);
});

it('keeps the tombstone out of the serialised model', function (): void {
    $user = User::factory()->create(['email_tombstone' => KNOWN_ADDRESS_TOMBSTONE]);

    // The profile form pushes this array into a public Livewire property, which Livewire writes verbatim into the DOM.
    expect($user->withoutRelations()->toArray())->not->toHaveKey('email_tombstone');
});
