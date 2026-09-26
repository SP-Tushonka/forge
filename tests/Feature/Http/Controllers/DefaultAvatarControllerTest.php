<?php

declare(strict_types=1);

it('draws the initials as a long-lived cacheable SVG without cookies', function (): void {
    $response = $this->get(route('avatar.default', ['initials' => 'JD']));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertHeader('Content-Security-Policy', "default-src 'none'");

    expect($response->getContent())->toStartWith('<svg')->toContain('>JD</text>')
        ->and($response->baseResponse->headers->getCookies())->toBeEmpty();
});

it('draws non-latin initials', function (): void {
    $this->get(route('avatar.default', ['initials' => 'ЁТ']))
        ->assertOk()
        ->assertSee('>ЁТ</text>', false);
});

it('draws a blank avatar for the blank marker', function (): void {
    $this->get(route('avatar.default', ['initials' => '-']))
        ->assertOk()
        ->assertSee('></text>', false);
});

it('rejects anything but one or two letters or digits', function (string $initials): void {
    $this->get(route('avatar.default', ['initials' => $initials]))->assertNotFound();
})->with([
    'too long' => ['ABC'],
    'markup' => ['<a'],
    'symbol' => ['&'],
    'two blanks' => ['--'],
    'trailing newline' => ["AB\n"],
]);
