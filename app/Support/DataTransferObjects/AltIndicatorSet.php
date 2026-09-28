<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\AltIndicatorType;

/**
 * Every indicator value in one account's own activity.
 */
final readonly class AltIndicatorSet
{
    /**
     * @param  array<string, array<string, true>>  $values  Values keyed by AltIndicatorType value, then by the value itself
     */
    public function __construct(
        public array $values = [],
    ) {}

    public function has(AltIndicatorType $type, string $value): bool
    {
        return isset($this->values[$type->value][$value]);
    }
}
