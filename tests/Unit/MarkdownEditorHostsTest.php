<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The editor's Preview tab calls previewMarkdown on the component that renders it, so a page that leaves the method out
 * throws MethodNotFoundException the first time somebody clicks Preview. Nothing else catches that.
 */
it('gives every page holding a markdown editor a preview method', function (): void {
    $templates = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->filter(fn (SplFileInfo $file): bool => str_contains(File::get($file->getPathname()), '<x-markdown-editor'));

    expect($templates)->not->toBeEmpty();

    $missing = $templates
        ->map(function (SplFileInfo $file): ?string {
            // A Volt single file component keeps its class beside its template. Anything else is a plain partial whose
            // host is whichever component renders it, and this test cannot tell which that is.
            $class = mb_substr($file->getPathname(), 0, -mb_strlen('.blade.php')).'.php';

            if (! File::exists($class)) {
                return null;
            }

            $code = File::get($class);

            // The trait applied inside the class body, not merely imported at the top of the file.
            if (preg_match('/^\s+use RendersMarkdownPreview;/m', $code) === 1) {
                return null;
            }

            if (str_contains($code, 'function previewMarkdown')) {
                return null;
            }

            return str_replace(resource_path('views').DIRECTORY_SEPARATOR, '', $file->getPathname());
        })
        ->filter()
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
