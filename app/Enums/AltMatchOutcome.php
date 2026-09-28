<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A staff member's verdict on an account that matched a watch.
 */
enum AltMatchOutcome: string
{
    case Confirmed = 'confirmed';

    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmed alt',
            self::Dismissed => 'Dismissed',
        };
    }
}
