<?php

declare(strict_types=1);

use App\Logging\DeduplicateDeprecations;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

it('defines a static deprecations log channel', function (): void {
    $channel = config()->array('logging.channels.deprecations');

    expect($channel['driver'])->toBe('daily')
        ->and($channel['path'])->toEndWith('deprecations.log')
        ->and($channel['tap'][0])->toStartWith(DeduplicateDeprecations::class.':');
});

it('routes deprecation warnings to the deprecations channel', function (): void {
    $basePath = storage_path('framework/testing/deprecations-'.Str::random(8).'.log');
    File::ensureDirectoryExists(dirname($basePath));
    config()->set('logging.channels.deprecations.path', $basePath);
    Log::forgetChannel('deprecations');

    // Unique per run: the tap keeps marker files under a shared storage path, so a fixed message
    // would be deduplicated away on the second run and fail here rather than in production.
    $message = 'Method Example'.Str::random(8).'::foo() is deprecated';

    $_SERVER['LOG_DEPRECATIONS_WHILE_TESTING'] = 'true';

    try {
        (new HandleExceptions)->handleDeprecationError($message, __FILE__, __LINE__);
    } finally {
        unset($_SERVER['LOG_DEPRECATIONS_WHILE_TESTING']);
    }

    $written = File::glob(str_replace('.log', '', $basePath).'*.log');

    expect($written)->not->toBeEmpty()
        ->and(File::get($written[0]))->toContain($message);

    File::delete($written);
});

it('collapses a repeated deprecation to a single line', function (): void {
    $basePath = storage_path('framework/testing/deprecations-'.Str::random(8).'.log');
    File::ensureDirectoryExists(dirname($basePath));
    config()->set('logging.channels.deprecations.path', $basePath);
    Log::forgetChannel('deprecations');

    $message = 'Method Example'.Str::random(8).'::bar() is deprecated';

    $_SERVER['LOG_DEPRECATIONS_WHILE_TESTING'] = 'true';

    try {
        for ($i = 0; $i < 500; $i++) {
            (new HandleExceptions)->handleDeprecationError($message, __FILE__, __LINE__);
        }
    } finally {
        unset($_SERVER['LOG_DEPRECATIONS_WHILE_TESTING']);
    }

    $written = File::glob(str_replace('.log', '', $basePath).'*.log');

    expect(mb_substr_count(File::get($written[0]), $message))->toBe(1);

    File::delete($written);
});
