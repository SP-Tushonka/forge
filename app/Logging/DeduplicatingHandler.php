<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;
use Monolog\LogRecord;
use Throwable;

/**
 * Collapses identical records so one repeating notice cannot fill the disk.
 *
 * A PHP deprecation raised inside a hot loop is logged once per occurrence: a single htmlpurifier
 * call site produced 57 million identical lines (15 GB) in a day here, which buried the handful of
 * notices that were worth reading. The first record of each distinct message is written and the
 * rest are dropped until the window expires.
 */
final class DeduplicatingHandler extends HandlerWrapper
{
    /**
     * Fingerprints already written by this process, so a repeat costs an array lookup and no syscall.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    public function __construct(
        HandlerInterface $handler,
        private readonly string $markerDirectory,
        private readonly int $window,
    ) {
        parent::__construct($handler);
    }

    public function handle(LogRecord $record): bool
    {
        // Non-cryptographic on purpose: this only has to name a file uniquely, never resist attack.
        $fingerprint = hash('xxh128', $record->level->name.'|'.$record->message);

        if (isset($this->seen[$fingerprint])) {
            return true;
        }

        $this->seen[$fingerprint] = true;

        if (! $this->claimWindow($fingerprint)) {
            return true;
        }

        return parent::handle($record);
    }

    /**
     * Claim the current window for this fingerprint, using a marker file's mtime as the clock.
     *
     * Deliberately syscall-only. A deprecation can be raised from inside the container, the cache
     * or the queue, so resolving a service here risks recursing through the code that is logging.
     */
    private function claimWindow(string $fingerprint): bool
    {
        $marker = $this->markerDirectory.DIRECTORY_SEPARATOR.$fingerprint;

        // Failing open throughout: if the marker cannot be read or written, $seen still caps this at
        // one line per process, which is a bounded amount of noise rather than a lost notice. A
        // logger must never propagate, and these calls raise as well as warn on a malformed path.
        try {
            $mtime = @filemtime($marker);

            if ($mtime !== false && $mtime > time() - $this->window) {
                return false;
            }

            if (! is_dir($this->markerDirectory)) {
                @mkdir($this->markerDirectory, 0775, true);
            }

            @touch($marker);
        } catch (Throwable) {
            // Intentionally ignored.
        }

        return true;
    }
}
