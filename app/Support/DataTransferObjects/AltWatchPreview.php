<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

/**
 * The accounts a watch matches today.
 */
final readonly class AltWatchPreview
{
    public const int MAX_MATCHES = 100;

    /**
     * @param  array<int, list<string>>  $matches  Keys of the matched indicators, per account id
     * @param  bool  $truncated  More candidates were found than were checked
     */
    public function __construct(
        public array $matches,
        public bool $truncated,
    ) {}

    public function count(): int
    {
        return count($this->matches);
    }

    public function tooBroad(): bool
    {
        return $this->truncated || $this->count() > self::MAX_MATCHES;
    }
}
