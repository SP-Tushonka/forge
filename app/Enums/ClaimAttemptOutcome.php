<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The result of initiating or verifying a mod claim, carrying the copy shown to the claimant.
 */
enum ClaimAttemptOutcome: string
{
    case Started = 'started';

    case Verified = 'verified';

    case NotFound = 'not_found';

    case NoAutomaticSource = 'no_automatic_source';

    case AlreadyOwned = 'already_owned';

    case Expired = 'expired';

    case Escalated = 'escalated';

    case RateLimited = 'rate_limited';

    /**
     * Toast heading for the outcome.
     */
    public function toastHeading(): string
    {
        return match ($this) {
            self::Started => 'Claim started',
            self::Verified => 'Mod claimed',
            self::NotFound => 'Not verified yet',
            self::NoAutomaticSource => 'Manual review needed',
            self::AlreadyOwned => 'Already claimed',
            self::Expired => 'Claim expired',
            self::Escalated => 'Sent for review',
            self::RateLimited => 'Too many attempts',
        };
    }

    /**
     * Toast body for the outcome.
     */
    public function toastText(?int $retryAfterSeconds = null): string
    {
        return match ($this) {
            self::Started => 'Add the token to your repository, then verify.',
            self::Verified => "You're now the owner of this mod.",
            self::NotFound => 'We could not find a matching claim.txt in the repository root of the linked source. Check the file and try again.',
            self::NoAutomaticSource => 'This mod has no source we can check automatically. Request a manual review and a moderator will take a look.',
            self::AlreadyOwned => 'This mod already has an owner.',
            self::Expired => 'This claim is past its window. Start a new one to get a fresh token.',
            self::Escalated => 'A moderator will review your claim, typically within 24-48 hours.',
            self::RateLimited => self::rateLimitedText($retryAfterSeconds),
        };
    }

    /**
     * Flux toast variant for the outcome.
     */
    public function toastVariant(): string
    {
        return match ($this) {
            self::Started, self::Escalated => 'default',
            self::Verified => 'success',
            self::NotFound, self::NoAutomaticSource, self::AlreadyOwned, self::Expired, self::RateLimited => 'danger',
        };
    }

    /**
     * Build the rate limit message, including the wait when it is known.
     */
    private static function rateLimitedText(?int $retryAfterSeconds): string
    {
        if ($retryAfterSeconds === null) {
            return 'Please wait before trying again.';
        }

        $minutes = (int) ceil($retryAfterSeconds / 60);

        return $minutes <= 1
            ? 'Please wait a moment before trying again.'
            : sprintf('Please wait %d minutes before trying again.', $minutes);
    }
}
