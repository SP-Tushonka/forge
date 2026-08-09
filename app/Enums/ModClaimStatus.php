<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle state of a mod ownership claim.
 */
enum ModClaimStatus: string
{
    /**
     * Awaiting proof. The claimant has a token but ownership has not been assigned, a claim in this state may be
     * awaiting an automated attempt, retry, or a moderator manual review.
     */
    case Pending = 'pending';

    /**
     * Proof accepted and ownership assigned to the claimant.
     */
    case Verified = 'verified';

    /**
     * Rejected, either by a moderator or because another user was assigned ownership first.
     */
    case Rejected = 'rejected';

    /**
     * The unescalated claim outlived its token window without being proven.
     */
    case Expired = 'expired';

    /**
     * Human readable label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }

    /**
     * Icon name for this status
     */
    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'clock',
            self::Verified => 'check-circle',
            self::Rejected => 'x-circle',
            self::Expired => 'exclamation-triangle',
        };
    }

    /**
     * Flux badge color for this status
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Verified => 'green',
            self::Rejected => 'red',
            self::Expired => 'amber',
        };
    }
}
