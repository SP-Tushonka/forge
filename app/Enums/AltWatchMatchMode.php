<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Whether a watch needs any or all of its ticked identifiers. Ticked qualifiers must always all match.
 */
enum AltWatchMatchMode: string
{
    case Any = 'any';

    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Any => 'Any identifier',
            self::All => 'All identifiers',
        };
    }
}
