<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Fonts, default avatars, video thumbnails and scripts are served from our own origin, so a visitor's browser tells no
 * third party that they are here. Content members post (hot-linked images) is disclosed in the privacy policy instead.
 */
it('references no third-party font, avatar, thumbnail or script host', function (): void {
    $hosts = [
        'fonts.bunny.net', 'fonts.googleapis.com', 'fonts.gstatic.com', 'ui-avatars.com', 'i.ytimg.com', 'unpkg.com',
        'cdn.jsdelivr.net', 'cdnjs.cloudflare.com',
    ];

    $offenders = collect([resource_path('views'), resource_path('css'), resource_path('js'), app_path()])
        ->flatMap(fn (string $directory): array => File::allFiles($directory))
        ->filter(fn (SplFileInfo $file): bool => Str::contains(File::get($file->getPathname()), $hosts))
        ->map(fn (SplFileInfo $file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('renders the maintenance page without loading anything from another origin', function (): void {
    $html = view('errors.503')->render();

    expect(preg_match('#(?:src|href)=["\'](?:https?:)?//#i', $html))->toBe(0);
});
