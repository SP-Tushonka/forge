<?php

declare(strict_types=1);

namespace App\Enums;

enum StaffActionType: string
{
    case EmailChanged = 'email_changed';

    case EmailRemoved = 'email_removed';

    case PasswordInvalidated = 'password_invalidated';

    case TwoFactorRemoved = 'two_factor_removed';

    case DiscordUnlinked = 'discord_unlinked';

    case AccountLocked = 'account_locked';

    case PhotoRemoved = 'photo_removed';

    public function subject(): string
    {
        return match ($this) {
            self::EmailChanged => 'Your email address was changed',
            self::EmailRemoved => 'Your email address was removed',
            self::PasswordInvalidated => 'Your password was reset by staff',
            self::TwoFactorRemoved => 'Two-factor authentication was removed',
            self::DiscordUnlinked => 'Your Discord connection was removed',
            self::AccountLocked => 'Your account was locked',
            self::PhotoRemoved => 'A profile image was removed',
        };
    }

    public function body(): string
    {
        return match ($this) {
            self::EmailChanged => 'A staff member changed the email address on your account. If you did not request this, contact us immediately.',
            self::EmailRemoved => 'A staff member removed the email address from your account. You will no longer receive email at this address.',
            self::PasswordInvalidated => 'A staff member invalidated your password. Use "forgot password" to set a new one.',
            self::TwoFactorRemoved => 'A staff member removed two-factor authentication from your account. We strongly recommend setting it up again.',
            self::DiscordUnlinked => 'A staff member removed the Discord connection from your account.',
            self::AccountLocked => 'A staff member locked your account. Contact us to restore access.',
            self::PhotoRemoved => 'A staff member removed a profile image from your account.',
        };
    }
}
