<?php

declare(strict_types=1);

namespace App\Enums;

enum VersionTagColor: string
{
    case Green = 'green';

    case Neutral = 'neutral';

    case Amber = 'amber';

    case Red = 'red';

    public function label(): string
    {
        return match ($this) {
            self::Green => 'Green',
            self::Neutral => 'Neutral',
            self::Amber => 'Amber',
            self::Red => 'Red',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::Green => 'green',
            self::Neutral => 'zinc',
            self::Amber => 'amber',
            self::Red => 'red',
        };
    }
}
