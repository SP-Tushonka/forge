<?php

declare(strict_types=1);

use App\Models\Emoji;

it('ships the twemoji svg directory', function (): void {
    expect(is_dir(public_path('vendor/twemoji/svg')))->toBeTrue(
        'public/vendor/twemoji/svg is missing. Run `npm run build`.'
    );
});

it('has artwork for the starter emoji codepoints', function (): void {
    foreach (['2764', '1f44d', '1f602', '1f525', '1f389'] as $codepoints) {
        expect(is_file(public_path('vendor/twemoji/svg/'.$codepoints.'.svg')))
            ->toBeTrue('Missing SVG for codepoints '.$codepoints);
    }
});

it('has artwork for every enabled emoji in the whitelist', function (): void {
    $missing = Emoji::query()->enabled()->get()
        ->reject(fn (Emoji $emoji): bool => is_file(public_path('vendor/twemoji/svg/'.$emoji->codepoints.'.svg')))
        ->map(fn (Emoji $emoji): string => $emoji->shortcode.' ('.$emoji->codepoints.')')
        ->all();

    expect($missing)->toBe([], 'Whitelisted emoji without artwork: '.implode(', ', $missing));
});
