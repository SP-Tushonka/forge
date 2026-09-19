<?php

declare(strict_types=1);

namespace App\Enums;

enum IssueNotificationLevel: string
{
    case All = 'all';

    case Bell = 'bell';

    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Bell and email',
            self::Bell => 'Bell only',
            self::Off => 'Off',
        };
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return match ($this) {
            self::All => ['database', 'mail'],
            self::Bell => ['database'],
            self::Off => [],
        };
    }
}
