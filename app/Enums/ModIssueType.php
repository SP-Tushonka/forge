<?php

declare(strict_types=1);

namespace App\Enums;

enum ModIssueType: string
{
    case Bug = 'bug';

    case Feature = 'feature';

    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Feature => 'Feature request',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'bug-ant',
            self::Feature => 'light-bulb',
        };
    }
}
