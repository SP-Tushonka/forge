<?php

declare(strict_types=1);

namespace App\Enums;

enum ModIssueStatus: string
{
    case New = 'new';

    case NeedsInfo = 'needs_info';

    case InProgress = 'in_progress';

    case Completed = 'completed';

    case WontImplement = 'wont_implement';

    case Duplicate = 'duplicate';

    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::New, self::NeedsInfo, self::InProgress];
    }

    /**
     * @return list<self>
     */
    public static function closed(): array
    {
        return [self::Completed, self::WontImplement, self::Duplicate, self::Closed];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * "Won't implement" reads wrongly on anything but a request, so the declined label follows the type.
     */
    public function label(ModIssueType $type): string
    {
        return match ($this) {
            self::New => 'New',
            self::NeedsInfo => 'Needs info',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::WontImplement => match ($type) {
                ModIssueType::Bug, ModIssueType::Compatibility => "Won't fix",
                ModIssueType::Question => "Won't answer",
                ModIssueType::Feature => "Won't implement",
            },
            self::Duplicate => 'Duplicate',
            self::Closed => 'Closed',
        };
    }

    public function badgeColor(): string
    {
        return match ($this) {
            self::New => 'blue',
            self::NeedsInfo => 'amber',
            self::InProgress => 'purple',
            self::Completed => 'green',
            self::WontImplement, self::Duplicate, self::Closed => 'zinc',
        };
    }
}
