<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a mod claim was proven. The three git hosts correspond to the automated-verification allowlist. Manual is a
 * moderator approval from the claim queue.
 */
enum ClaimVerificationMethod: string
{
    case GitHub = 'github';

    case GitLab = 'gitlab';

    case Gitea = 'gitea';

    case Manual = 'manual';

    /**
     * Human-readable label for the method.
     */
    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Gitea => 'Gitea',
            self::Manual => 'Manual review',
        };
    }
}
