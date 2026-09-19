<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\InvalidVersionNumberException;
use App\Support\Version;

enum VersionChange: string
{
    case Major = 'major';

    case Minor = 'minor';

    case Patch = 'patch';

    /**
     * The most significant part that differs between two versions, or null when they are the same version.
     *
     * A difference only in the label (pre-release or build metadata) counts as a patch change: on the Forge a label
     * usually marks a hotfix build or a different SPT target. Unparseable versions count as a major change because
     * there is no telling how far apart they are.
     */
    public static function between(string $from, string $to): ?self
    {
        if ($from === $to) {
            return null;
        }

        try {
            $a = new Version($from);
            $b = new Version($to);
        } catch (InvalidVersionNumberException) {
            return self::Major;
        }

        return match (true) {
            $a->getMajor() !== $b->getMajor() => self::Major,
            $a->getMinor() !== $b->getMinor() => self::Minor,
            $a->getPatch() !== $b->getPatch(), $a->getLabels() !== $b->getLabels() => self::Patch,
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function defaultTagColors(): array
    {
        $colors = [];

        foreach (self::cases() as $change) {
            $colors[$change->value] = $change->defaultTagColor()->value;
        }

        return $colors;
    }

    public function defaultTagColor(): VersionTagColor
    {
        return $this === self::Major ? VersionTagColor::Red : VersionTagColor::Amber;
    }

    public function label(): string
    {
        return match ($this) {
            self::Major => 'Major change (1.x → 2.x)',
            self::Minor => 'Minor change (1.2 → 1.3)',
            self::Patch => 'Patch or label change (1.2.0 → 1.2.1)',
        };
    }
}
