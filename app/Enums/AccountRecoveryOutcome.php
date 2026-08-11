<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The result of requesting or completing an archived account recovery, carrying the copy shown to the claimant.
 */
enum AccountRecoveryOutcome: string
{
    case LinkSent = 'link_sent';

    case NoMatch = 'no_match';

    case Recovered = 'recovered';

    case InvalidLink = 'invalid_link';

    case ExpiredLink = 'expired_link';

    case AlreadyUsed = 'already_used';

    case EmailTaken = 'email_taken';

    case RateLimited = 'rate_limited';

    /**
     * Toast heading for the outcome.
     */
    public function toastHeading(): string
    {
        return match ($this) {
            self::LinkSent, self::NoMatch => 'Check your inbox',
            self::Recovered => 'Welcome back',
            self::InvalidLink => 'Link not recognised',
            self::ExpiredLink => 'Link expired',
            self::AlreadyUsed => 'Link already used',
            self::EmailTaken => 'Address unavailable',
            self::RateLimited => 'Too many attempts',
        };
    }

    /**
     * Toast body for the outcome.
     */
    public function toastText(?int $retryAfterSeconds = null): string
    {
        return match ($this) {
            self::LinkSent, self::NoMatch => 'If that address had an account on the old Forge, we have sent it a recovery link. It expires in an hour.',
            self::Recovered => 'Your account has been restored, along with everything attached to it.',
            self::InvalidLink => 'That recovery link is not valid. Request a new one to try again.',
            self::ExpiredLink => 'That recovery link has expired. Request a new one to try again.',
            self::AlreadyUsed => 'That recovery link has already been used. Sign in, or reset your password if you cannot.',
            self::EmailTaken => 'Another account is already using that address. Contact us and we will sort it out.',
            self::RateLimited => self::rateLimitedText($retryAfterSeconds),
        };
    }

    /**
     * Flux toast variant for the outcome.
     */
    public function toastVariant(): string
    {
        return match ($this) {
            self::LinkSent, self::NoMatch => 'default',
            self::Recovered => 'success',
            self::InvalidLink, self::ExpiredLink, self::AlreadyUsed, self::EmailTaken, self::RateLimited => 'danger',
        };
    }

    /**
     * Build the rate limit message
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
