<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\AltWatchMatchMode;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;

/**
 * A watch's rule, saved or not: whose watch it is, the ticked indicators and the match mode.
 */
final readonly class AltWatchDraft
{
    /**
     * @param  list<AltIndicator>  $indicators
     */
    public function __construct(
        public ?int $watchedUserId,
        public AltWatchMatchMode $mode,
        public array $indicators,
    ) {}

    /**
     * Needs the watch's indicators loaded.
     */
    public static function fromWatch(AltWatch $watch): self
    {
        return new self(
            $watch->watched_user_id,
            $watch->match_mode,
            array_values($watch->indicators->map(static fn (AltWatchIndicator $indicator): AltIndicator => $indicator->toIndicator())->all()),
        );
    }

    /**
     * @return list<AltIndicator>
     */
    public function identifiers(): array
    {
        return array_values(array_filter($this->indicators, static fn (AltIndicator $indicator): bool => $indicator->type->isIdentifier()));
    }

    /**
     * @return list<AltIndicator>
     */
    public function qualifiers(): array
    {
        return array_values(array_filter($this->indicators, static fn (AltIndicator $indicator): bool => ! $indicator->type->isIdentifier()));
    }
}
