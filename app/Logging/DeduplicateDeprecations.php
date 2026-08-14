<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Channel tap that wraps every handler in {@see DeduplicatingHandler}.
 */
final class DeduplicateDeprecations
{
    public function __invoke(Logger $logger, int|string $window = 3600): void
    {
        // Laravel passes '' when the tap is configured without ':seconds', so the signature default
        // never applies; fall back here instead or the window collapses to zero.
        $seconds = (int) $window ?: 3600;

        $monolog = $logger->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        $directory = storage_path('framework/deprecations');

        $monolog->setHandlers(array_map(
            fn (HandlerInterface $handler) => new DeduplicatingHandler($handler, $directory, $seconds),
            $monolog->getHandlers(),
        ));
    }
}
