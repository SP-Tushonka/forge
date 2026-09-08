<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Comment;
use App\Models\Mod;
use App\Models\ModList;
use App\Models\ModVersion;
use App\Models\User;

enum TrackingEventType: string
{
    /** User authentication events */
    case LOGIN = 'login';

    case LOGOUT = 'logout';

    case REGISTER = 'register';

    case PASSWORD_CHANGE = 'password_change';

    /** Mod-related events */
    case MOD_DOWNLOAD = 'mod_download';

    case MOD_CREATE = 'mod_create';

    case MOD_EDIT = 'mod_edit';

    case MOD_DELETE = 'mod_delete';

    case MOD_REPORT = 'mod_report';

    case MOD_CLAIM_INITIATED = 'mod_claim_initiated';

    case MOD_CLAIM_VERIFIED = 'mod_claim_verified';

    case MOD_CLAIM_REJECTED = 'mod_claim_rejected';

    /** Mod version events */
    case VERSION_CREATE = 'version_create';

    case VERSION_EDIT = 'version_edit';

    case VERSION_DELETE = 'version_delete';

    case VERSION_DISABLE = 'version_disable';

    case VERSION_ENABLE = 'version_enable';

    case VERSION_PUBLISH = 'version_publish';

    case VERSION_UNPUBLISH = 'version_unpublish';

    /** Addon-related events */
    case ADDON_DOWNLOAD = 'addon_download';

    case ADDON_CREATE = 'addon_create';

    case ADDON_EDIT = 'addon_edit';

    case ADDON_DELETE = 'addon_delete';

    case ADDON_REPORT = 'addon_report';

    case ADDON_ATTACH = 'addon_attach';

    case ADDON_DETACH = 'addon_detach';

    case ADDON_DISABLE = 'addon_disable';

    case ADDON_ENABLE = 'addon_enable';

    case ADDON_PUBLISH = 'addon_publish';

    case ADDON_UNPUBLISH = 'addon_unpublish';

    /** Addon version events */
    case ADDON_VERSION_CREATE = 'addon_version_create';

    case ADDON_VERSION_EDIT = 'addon_version_edit';

    case ADDON_VERSION_DELETE = 'addon_version_delete';

    case ADDON_VERSION_DISABLE = 'addon_version_disable';

    case ADDON_VERSION_ENABLE = 'addon_version_enable';

    case ADDON_VERSION_PUBLISH = 'addon_version_publish';

    case ADDON_VERSION_UNPUBLISH = 'addon_version_unpublish';

    /** Comment events */
    case COMMENT_CREATE = 'comment_create';

    case COMMENT_EDIT = 'comment_edit';

    case COMMENT_SOFT_DELETE = 'comment_soft_delete';

    case COMMENT_HARD_DELETE = 'comment_hard_delete';

    case COMMENT_LIKE = 'comment_like';

    case COMMENT_UNLIKE = 'comment_unlike';

    case COMMENT_REPORT = 'comment_report';

    /** Account management events */
    case ACCOUNT_DELETE = 'account_delete';

    /** Moderator/Staff actions */
    case USER_BAN = 'user_ban';

    case USER_UNBAN = 'user_unban';

    /** User received moderation actions (from banned user's perspective) */
    case USER_BANNED = 'user_banned';

    case USER_UNBANNED = 'user_unbanned';

    case IP_BAN = 'ip_ban';

    case IP_UNBAN = 'ip_unban';

    case ALT_INVESTIGATION = 'alt_investigation';

    case USER_EMAIL_CHANGE = 'user_email_change';

    case USER_EMAIL_REMOVE = 'user_email_remove';

    case USER_PASSWORD_RESET_SENT = 'user_password_reset_sent';

    case USER_PASSWORD_INVALIDATE = 'user_password_invalidate';

    case USER_MFA_REMOVE = 'user_mfa_remove';

    case USER_DISCORD_UNLINK = 'user_discord_unlink';

    case USER_ACCOUNT_LOCK = 'user_account_lock';

    case USER_PHOTO_REMOVE = 'user_photo_remove';

    case MOD_FEATURE = 'mod_feature';

    case MOD_UNFEATURE = 'mod_unfeature';

    case MOD_DISABLE = 'mod_disable';

    case MOD_ENABLE = 'mod_enable';

    case MOD_PUBLISH = 'mod_publish';

    case MOD_UNPUBLISH = 'mod_unpublish';

    case MOD_OWNERSHIP_TRANSFER = 'mod_ownership_transfer';

    case MOD_OWNERSHIP_CLEARED = 'mod_ownership_cleared';

    case COMMENT_PIN = 'comment_pin';

    case COMMENT_UNPIN = 'comment_unpin';

    case COMMENT_RESTORE = 'comment_restore';

    case COMMENT_MARK_SPAM = 'comment_mark_spam';

    case COMMENT_MARK_CLEAN = 'comment_mark_clean';

    case MOD_LIST_DISABLE = 'mod_list_disable';

    case MOD_LIST_ENABLE = 'mod_list_enable';

    case MOD_LIST_DELETE = 'mod_list_delete';

    /**
     * Get all moderation action event types.
     *
     * @return array<int, self>
     */
    public static function moderationActions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => $type->isModerationAction()
        ));
    }

    /**
     * Get the user-friendly display name for this event type.
     */
    public function label(): string
    {
        return $this->getName();
    }

    /**
     * Get the user-friendly display name for this event type.
     */
    public function getName(): string
    {
        return match ($this) {
            self::LOGIN => 'Logged in',
            self::LOGOUT => 'Logged out',
            self::REGISTER => 'Registered account',
            self::PASSWORD_CHANGE => 'Changed password',
            self::MOD_DOWNLOAD => 'Downloaded mod',
            self::MOD_CREATE => 'Created mod',
            self::MOD_EDIT => 'Edited mod',
            self::MOD_DELETE => 'Deleted mod',
            self::VERSION_CREATE => 'Created mod version',
            self::VERSION_EDIT => 'Edited mod version',
            self::VERSION_DELETE => 'Deleted mod version',
            self::VERSION_DISABLE => 'Disabled mod version',
            self::VERSION_ENABLE => 'Enabled mod version',
            self::VERSION_PUBLISH => 'Published mod version',
            self::VERSION_UNPUBLISH => 'Unpublished mod version',
            self::ADDON_DOWNLOAD => 'Downloaded addon',
            self::ADDON_CREATE => 'Created addon',
            self::ADDON_EDIT => 'Edited addon',
            self::ADDON_DELETE => 'Deleted addon',
            self::ADDON_REPORT => 'Reported addon',
            self::ADDON_ATTACH => 'Attached addon',
            self::ADDON_DETACH => 'Detached addon',
            self::ADDON_DISABLE => 'Disabled addon',
            self::ADDON_ENABLE => 'Enabled addon',
            self::ADDON_PUBLISH => 'Published addon',
            self::ADDON_UNPUBLISH => 'Unpublished addon',
            self::ADDON_VERSION_CREATE => 'Created addon version',
            self::ADDON_VERSION_EDIT => 'Edited addon version',
            self::ADDON_VERSION_DELETE => 'Deleted addon version',
            self::ADDON_VERSION_DISABLE => 'Disabled addon version',
            self::ADDON_VERSION_ENABLE => 'Enabled addon version',
            self::ADDON_VERSION_PUBLISH => 'Published addon version',
            self::ADDON_VERSION_UNPUBLISH => 'Unpublished addon version',
            self::COMMENT_CREATE => 'Created comment',
            self::COMMENT_EDIT => 'Edited comment',
            self::COMMENT_SOFT_DELETE => 'Soft deleted comment',
            self::COMMENT_HARD_DELETE => 'Hard deleted comment',
            self::COMMENT_LIKE => 'Liked comment',
            self::COMMENT_UNLIKE => 'Unliked comment',
            self::COMMENT_REPORT => 'Reported comment',
            self::MOD_REPORT => 'Reported mod',
            self::MOD_CLAIM_INITIATED => 'Started mod claim',
            self::MOD_CLAIM_VERIFIED => 'Claimed mod',
            self::MOD_CLAIM_REJECTED => 'Rejected mod claim',
            self::ACCOUNT_DELETE => 'Deleted account',
            self::USER_BAN => 'Banned user',
            self::USER_UNBAN => 'Unbanned user',
            self::USER_BANNED => 'Was banned',
            self::USER_UNBANNED => 'Was unbanned',
            self::IP_BAN => 'Banned IP address',
            self::IP_UNBAN => 'Unbanned IP address',
            self::ALT_INVESTIGATION => 'Investigated alt accounts',
            self::MOD_FEATURE => 'Featured mod',
            self::MOD_UNFEATURE => 'Unfeatured mod',
            self::MOD_DISABLE => 'Disabled mod',
            self::MOD_ENABLE => 'Enabled mod',
            self::MOD_PUBLISH => 'Published mod',
            self::MOD_UNPUBLISH => 'Unpublished mod',
            self::MOD_OWNERSHIP_TRANSFER => 'Transferred mod ownership',
            self::MOD_OWNERSHIP_CLEARED => 'Cleared mod ownership',
            self::COMMENT_PIN => 'Pinned comment',
            self::COMMENT_UNPIN => 'Unpinned comment',
            self::COMMENT_RESTORE => 'Restored comment',
            self::COMMENT_MARK_SPAM => 'Marked comment as spam',
            self::COMMENT_MARK_CLEAN => 'Marked comment as clean',
            self::MOD_LIST_DISABLE => 'Disabled mod list',
            self::MOD_LIST_ENABLE => 'Enabled mod list',
            self::MOD_LIST_DELETE => 'Deleted mod list',
            self::USER_EMAIL_CHANGE => 'Changed user email',
            self::USER_EMAIL_REMOVE => 'Removed user email',
            self::USER_PASSWORD_RESET_SENT => 'Sent password reset',
            self::USER_PASSWORD_INVALIDATE => 'Invalidated password',
            self::USER_MFA_REMOVE => 'Removed two-factor authentication',
            self::USER_DISCORD_UNLINK => 'Unlinked Discord connection',
            self::USER_ACCOUNT_LOCK => 'Locked account',
            self::USER_PHOTO_REMOVE => 'Removed profile image',
        };
    }

    /**
     * Get a detailed description of what this event represents.
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::LOGIN => 'User successfully logged in',
            self::LOGOUT => 'User logged out',
            self::REGISTER => 'User created a new account',
            self::PASSWORD_CHANGE => 'User changed their password',
            self::MOD_DOWNLOAD => 'User downloaded a mod',
            self::MOD_CREATE => 'User created a new mod',
            self::MOD_EDIT => 'User edited a mod',
            self::MOD_DELETE => 'User deleted a mod',
            self::VERSION_CREATE => 'User created a new mod version',
            self::VERSION_EDIT => 'User edited a mod version',
            self::VERSION_DELETE => 'User deleted a mod version',
            self::VERSION_DISABLE => 'Moderator disabled a mod version',
            self::VERSION_ENABLE => 'Moderator enabled a mod version',
            self::VERSION_PUBLISH => 'Moderator published a mod version',
            self::VERSION_UNPUBLISH => 'Moderator unpublished a mod version',
            self::ADDON_DOWNLOAD => 'User downloaded an addon',
            self::ADDON_CREATE => 'User created a new addon',
            self::ADDON_EDIT => 'User edited an addon',
            self::ADDON_DELETE => 'User deleted an addon',
            self::ADDON_REPORT => 'User reported an addon',
            self::ADDON_ATTACH => 'User attached an addon to its parent mod',
            self::ADDON_DETACH => 'User detached an addon from its parent mod',
            self::ADDON_DISABLE => 'Moderator disabled an addon',
            self::ADDON_ENABLE => 'Moderator enabled an addon',
            self::ADDON_PUBLISH => 'Moderator published an addon',
            self::ADDON_UNPUBLISH => 'Moderator unpublished an addon',
            self::ADDON_VERSION_CREATE => 'User created a new addon version',
            self::ADDON_VERSION_EDIT => 'User edited an addon version',
            self::ADDON_VERSION_DELETE => 'User deleted an addon version',
            self::ADDON_VERSION_DISABLE => 'Moderator disabled an addon version',
            self::ADDON_VERSION_ENABLE => 'Moderator enabled an addon version',
            self::ADDON_VERSION_PUBLISH => 'Moderator published an addon version',
            self::ADDON_VERSION_UNPUBLISH => 'Moderator unpublished an addon version',
            self::COMMENT_CREATE => 'User created a comment',
            self::COMMENT_EDIT => 'User edited a comment',
            self::COMMENT_SOFT_DELETE => 'User soft deleted a comment',
            self::COMMENT_HARD_DELETE => 'Staff hard deleted a comment',
            self::COMMENT_LIKE => 'User liked a comment',
            self::COMMENT_UNLIKE => 'User unliked a comment',
            self::COMMENT_REPORT => 'User reported a comment',
            self::MOD_REPORT => 'User reported a mod',
            self::MOD_CLAIM_INITIATED => 'User started a claim on an unowned mod',
            self::MOD_CLAIM_VERIFIED => 'User proved ownership and was assigned the mod',
            self::MOD_CLAIM_REJECTED => 'A mod ownership claim was rejected',
            self::ACCOUNT_DELETE => 'User deleted their account',
            self::USER_BAN => 'Moderator banned a user',
            self::USER_UNBAN => 'Moderator unbanned a user',
            self::USER_BANNED => 'User was banned by a moderator',
            self::USER_UNBANNED => 'User was unbanned by a moderator',
            self::IP_BAN => 'Moderator banned an IP address',
            self::IP_UNBAN => 'Moderator unbanned an IP address',
            self::ALT_INVESTIGATION => 'Staff investigated a user for alternate accounts',
            self::MOD_FEATURE => 'Moderator featured a mod',
            self::MOD_UNFEATURE => 'Moderator unfeatured a mod',
            self::MOD_DISABLE => 'Moderator disabled a mod',
            self::MOD_ENABLE => 'Moderator enabled a mod',
            self::MOD_PUBLISH => 'Moderator published a mod',
            self::MOD_UNPUBLISH => 'Moderator unpublished a mod',
            self::MOD_OWNERSHIP_TRANSFER => 'Moderator transferred a mod to a different owner',
            self::MOD_OWNERSHIP_CLEARED => 'Moderator removed a mod owner, returning the mod to the claimable pool',
            self::COMMENT_PIN => 'Moderator pinned a comment',
            self::COMMENT_UNPIN => 'Moderator unpinned a comment',
            self::COMMENT_RESTORE => 'Moderator restored a deleted comment',
            self::COMMENT_MARK_SPAM => 'Moderator marked a comment as spam',
            self::COMMENT_MARK_CLEAN => 'Moderator marked a comment as clean',
            self::MOD_LIST_DISABLE => 'Moderator disabled a mod list',
            self::MOD_LIST_ENABLE => 'Moderator enabled a mod list',
            self::MOD_LIST_DELETE => 'Moderator deleted a mod list',
            self::USER_EMAIL_CHANGE => 'A staff member changed this account\'s email address',
            self::USER_EMAIL_REMOVE => 'A staff member detached this account\'s email address',
            self::USER_PASSWORD_RESET_SENT => 'A staff member sent this account a password reset link',
            self::USER_PASSWORD_INVALIDATE => 'A staff member invalidated this account\'s password',
            self::USER_MFA_REMOVE => 'A staff member removed two-factor authentication from this account',
            self::USER_DISCORD_UNLINK => 'A staff member unlinked this account\'s Discord connection',
            self::USER_ACCOUNT_LOCK => 'A staff member locked this account for incident response',
            self::USER_PHOTO_REMOVE => 'A staff member removed a profile image from this account',
        };
    }

    /**
     * Get the fully qualified class name of the model this event can track.
     */
    public function getTrackableModel(): ?string
    {
        return match ($this) {
            self::MOD_CREATE, self::MOD_EDIT, self::MOD_DELETE, self::MOD_REPORT, self::MOD_FEATURE, self::MOD_UNFEATURE, self::MOD_DISABLE, self::MOD_ENABLE, self::MOD_PUBLISH, self::MOD_UNPUBLISH, self::MOD_CLAIM_INITIATED, self::MOD_CLAIM_VERIFIED, self::MOD_CLAIM_REJECTED, self::MOD_OWNERSHIP_TRANSFER, self::MOD_OWNERSHIP_CLEARED => Mod::class,
            self::MOD_DOWNLOAD, self::VERSION_CREATE, self::VERSION_EDIT, self::VERSION_DELETE, self::VERSION_DISABLE, self::VERSION_ENABLE, self::VERSION_PUBLISH, self::VERSION_UNPUBLISH => ModVersion::class,
            self::ADDON_CREATE, self::ADDON_EDIT, self::ADDON_DELETE, self::ADDON_REPORT, self::ADDON_ATTACH, self::ADDON_DETACH, self::ADDON_DISABLE, self::ADDON_ENABLE, self::ADDON_PUBLISH, self::ADDON_UNPUBLISH => Addon::class,
            self::ADDON_DOWNLOAD, self::ADDON_VERSION_CREATE, self::ADDON_VERSION_EDIT, self::ADDON_VERSION_DELETE, self::ADDON_VERSION_DISABLE, self::ADDON_VERSION_ENABLE, self::ADDON_VERSION_PUBLISH, self::ADDON_VERSION_UNPUBLISH => AddonVersion::class,
            self::COMMENT_CREATE, self::COMMENT_EDIT, self::COMMENT_SOFT_DELETE, self::COMMENT_HARD_DELETE, self::COMMENT_LIKE, self::COMMENT_UNLIKE, self::COMMENT_REPORT, self::COMMENT_PIN, self::COMMENT_UNPIN, self::COMMENT_RESTORE, self::COMMENT_MARK_SPAM, self::COMMENT_MARK_CLEAN => Comment::class,
            self::USER_BAN, self::USER_UNBAN, self::USER_BANNED, self::USER_UNBANNED, self::ALT_INVESTIGATION,
            self::USER_EMAIL_CHANGE, self::USER_EMAIL_REMOVE, self::USER_PASSWORD_RESET_SENT,
            self::USER_PASSWORD_INVALIDATE, self::USER_MFA_REMOVE, self::USER_DISCORD_UNLINK,
            self::USER_ACCOUNT_LOCK, self::USER_PHOTO_REMOVE => User::class,
            self::MOD_LIST_DISABLE, self::MOD_LIST_ENABLE, self::MOD_LIST_DELETE => ModList::class,
            default => null,
        };
    }

    /**
     * Determine if this event type requires a trackable model instance.
     *
     * Returns true if the event should be associated with a specific model instance (e.g., downloading a specific mod),
     * false if it's a general event that doesn't relate to a specific model (e.g., user login).
     */
    public function requiresTrackable(): bool
    {
        return $this->getTrackableModel() !== null;
    }

    /**
     * Get the Flux UI icon name for this event type.
     */
    public function getIcon(): string
    {
        return match ($this) {
            self::LOGIN => 'arrow-right-end-on-rectangle',
            self::LOGOUT => 'arrow-left-start-on-rectangle',
            self::REGISTER => 'user-plus',
            self::PASSWORD_CHANGE => 'key',
            self::MOD_DOWNLOAD => 'arrow-down-tray',
            self::MOD_CREATE => 'plus-circle',
            self::MOD_EDIT => 'pencil-square',
            self::MOD_DELETE => 'trash',
            self::MOD_REPORT => 'flag',
            self::MOD_CLAIM_INITIATED => 'hand-raised',
            self::MOD_CLAIM_VERIFIED => 'check-badge',
            self::MOD_CLAIM_REJECTED => 'x-circle',
            self::VERSION_CREATE => 'tag',
            self::VERSION_EDIT => 'pencil',
            self::VERSION_DELETE => 'x-circle',
            self::VERSION_DISABLE => 'eye-slash',
            self::VERSION_ENABLE => 'eye',
            self::VERSION_PUBLISH => 'globe-alt',
            self::VERSION_UNPUBLISH => 'eye-slash',
            self::ADDON_DOWNLOAD => 'arrow-down-tray',
            self::ADDON_CREATE => 'plus-circle',
            self::ADDON_EDIT => 'pencil-square',
            self::ADDON_DELETE => 'trash',
            self::ADDON_REPORT => 'flag',
            self::ADDON_ATTACH => 'link',
            self::ADDON_DETACH => 'link-slash',
            self::ADDON_DISABLE => 'eye-slash',
            self::ADDON_ENABLE => 'eye',
            self::ADDON_PUBLISH => 'globe-alt',
            self::ADDON_UNPUBLISH => 'eye-slash',
            self::ADDON_VERSION_CREATE => 'tag',
            self::ADDON_VERSION_EDIT => 'pencil',
            self::ADDON_VERSION_DELETE => 'x-circle',
            self::ADDON_VERSION_DISABLE => 'eye-slash',
            self::ADDON_VERSION_ENABLE => 'eye',
            self::ADDON_VERSION_PUBLISH => 'globe-alt',
            self::ADDON_VERSION_UNPUBLISH => 'eye-slash',
            self::COMMENT_CREATE => 'chat-bubble-left',
            self::COMMENT_EDIT => 'chat-bubble-left-ellipsis',
            self::COMMENT_SOFT_DELETE => 'chat-bubble-left-right',
            self::COMMENT_HARD_DELETE => 'trash',
            self::COMMENT_LIKE => 'heart',
            self::COMMENT_UNLIKE => 'heart',
            self::COMMENT_REPORT => 'exclamation-triangle',
            self::ACCOUNT_DELETE => 'user-minus',
            self::USER_BAN => 'no-symbol',
            self::USER_UNBAN => 'check-circle',
            self::USER_BANNED => 'no-symbol',
            self::USER_UNBANNED => 'check-circle',
            self::IP_BAN => 'shield-exclamation',
            self::IP_UNBAN => 'shield-check',
            self::ALT_INVESTIGATION => 'finger-print',
            self::MOD_FEATURE => 'star',
            self::MOD_UNFEATURE => 'star',
            self::MOD_DISABLE => 'eye-slash',
            self::MOD_ENABLE => 'eye',
            self::MOD_PUBLISH => 'globe-alt',
            self::MOD_UNPUBLISH => 'eye-slash',
            self::MOD_OWNERSHIP_TRANSFER => 'arrows-right-left',
            self::MOD_OWNERSHIP_CLEARED => 'user-minus',
            self::COMMENT_PIN => 'bookmark',
            self::COMMENT_UNPIN => 'bookmark',
            self::COMMENT_RESTORE => 'arrow-uturn-left',
            self::COMMENT_MARK_SPAM => 'shield-exclamation',
            self::COMMENT_MARK_CLEAN => 'shield-check',
            self::MOD_LIST_DISABLE => 'eye-slash',
            self::MOD_LIST_ENABLE => 'eye',
            self::MOD_LIST_DELETE => 'trash',
            self::USER_EMAIL_CHANGE, self::USER_EMAIL_REMOVE => 'envelope',
            self::USER_PASSWORD_RESET_SENT, self::USER_PASSWORD_INVALIDATE => 'key',
            self::USER_MFA_REMOVE => 'shield-exclamation',
            self::USER_DISCORD_UNLINK => 'link-slash',
            self::USER_ACCOUNT_LOCK => 'lock-closed',
            self::USER_PHOTO_REMOVE => 'photo',
        };
    }

    /**
     * Get the Flux UI badge color for this event type.
     */
    public function getColor(): string
    {
        return match ($this) {
            // Authentication events - Blue/Cyan theme
            self::LOGIN => 'blue',
            self::LOGOUT => 'cyan',
            self::REGISTER => 'green',
            self::PASSWORD_CHANGE => 'indigo',

            // Mod events - Purple/Violet theme
            self::MOD_DOWNLOAD => 'purple',
            self::MOD_CREATE => 'violet',
            self::MOD_EDIT => 'indigo',
            self::MOD_DELETE => 'red',
            self::MOD_REPORT => 'orange',
            self::MOD_CLAIM_INITIATED => 'gray',
            self::MOD_CLAIM_VERIFIED => 'green',
            self::MOD_CLAIM_REJECTED => 'red',

            // Version events - Teal/Emerald theme
            self::VERSION_CREATE => 'teal',
            self::VERSION_EDIT => 'emerald',
            self::VERSION_DELETE => 'red',
            self::VERSION_DISABLE => 'red',
            self::VERSION_ENABLE => 'green',
            self::VERSION_PUBLISH => 'blue',
            self::VERSION_UNPUBLISH => 'gray',

            // Addon events - Fuchsia/Pink theme
            self::ADDON_DOWNLOAD => 'fuchsia',
            self::ADDON_CREATE => 'pink',
            self::ADDON_EDIT => 'rose',
            self::ADDON_DELETE => 'red',
            self::ADDON_REPORT => 'orange',
            self::ADDON_ATTACH => 'green',
            self::ADDON_DETACH => 'amber',
            self::ADDON_DISABLE => 'red',
            self::ADDON_ENABLE => 'green',
            self::ADDON_PUBLISH => 'blue',
            self::ADDON_UNPUBLISH => 'gray',

            // Addon version events - Sky/Cyan theme
            self::ADDON_VERSION_CREATE => 'sky',
            self::ADDON_VERSION_EDIT => 'cyan',
            self::ADDON_VERSION_DELETE => 'red',
            self::ADDON_VERSION_DISABLE => 'red',
            self::ADDON_VERSION_ENABLE => 'green',
            self::ADDON_VERSION_PUBLISH => 'blue',
            self::ADDON_VERSION_UNPUBLISH => 'gray',

            // Comment events - Yellow/Amber theme
            self::COMMENT_CREATE => 'yellow',
            self::COMMENT_EDIT => 'amber',
            self::COMMENT_SOFT_DELETE => 'red',
            self::COMMENT_HARD_DELETE => 'red',
            self::COMMENT_LIKE => 'pink',
            self::COMMENT_UNLIKE => 'zinc',
            self::COMMENT_REPORT => 'orange',

            // Account management - Rose theme
            self::ACCOUNT_DELETE => 'rose',

            // Moderation actions - Red/Orange/Gray theme
            self::USER_BAN => 'red',
            self::USER_UNBAN => 'green',
            self::USER_BANNED => 'red',
            self::USER_UNBANNED => 'green',
            self::IP_BAN => 'red',
            self::IP_UNBAN => 'green',
            self::ALT_INVESTIGATION => 'amber',
            self::MOD_FEATURE => 'yellow',
            self::MOD_UNFEATURE => 'gray',
            self::MOD_DISABLE => 'red',
            self::MOD_ENABLE => 'green',
            self::MOD_PUBLISH => 'blue',
            self::MOD_UNPUBLISH => 'gray',
            self::MOD_OWNERSHIP_TRANSFER => 'blue',
            self::MOD_OWNERSHIP_CLEARED => 'red',
            self::COMMENT_PIN => 'blue',
            self::COMMENT_UNPIN => 'gray',
            self::COMMENT_RESTORE => 'green',
            self::COMMENT_MARK_SPAM => 'red',
            self::COMMENT_MARK_CLEAN => 'green',
            self::MOD_LIST_DISABLE => 'red',
            self::MOD_LIST_ENABLE => 'green',
            self::MOD_LIST_DELETE => 'red',
            self::USER_EMAIL_CHANGE, self::USER_PASSWORD_RESET_SENT, self::USER_PHOTO_REMOVE => 'amber',
            self::USER_EMAIL_REMOVE, self::USER_PASSWORD_INVALIDATE, self::USER_MFA_REMOVE,
            self::USER_DISCORD_UNLINK, self::USER_ACCOUNT_LOCK => 'red',
        };
    }

    /**
     * Determine if this event type should show a URL/link.
     */
    public function shouldShowUrl(): bool
    {
        return match ($this) {
            self::LOGIN, self::LOGOUT, self::REGISTER, self::PASSWORD_CHANGE => false,
            default => true,
        };
    }

    /**
     * Determine if this event type should show context text.
     */
    public function shouldShowContext(): bool
    {
        return match ($this) {
            self::LOGIN, self::LOGOUT, self::REGISTER, self::PASSWORD_CHANGE => false,
            default => true,
        };
    }

    /**
     * Determine if this event type should be private (not shown to other users).
     * Private events are only visible to the user themselves, moderators, and administrators.
     *
     * Most events are private by default, except for explicitly public actions like making comments.
     */
    public function isPrivate(): bool
    {
        return match ($this) {
            self::COMMENT_CREATE, self::COMMENT_EDIT, self::COMMENT_SOFT_DELETE, self::COMMENT_LIKE, self::COMMENT_UNLIKE => false,
            default => true,
        };
    }

    /**
     * Determine if this event type represents a moderation action.
     * These are actions performed by moderators/administrators on users or content.
     */
    public function isModerationAction(): bool
    {
        return match ($this) {
            self::USER_BAN,
            self::USER_UNBAN,
            self::IP_BAN,
            self::IP_UNBAN,
            self::ALT_INVESTIGATION,
            self::MOD_FEATURE,
            self::MOD_UNFEATURE,
            self::MOD_DISABLE,
            self::MOD_ENABLE,
            self::MOD_PUBLISH,
            self::MOD_UNPUBLISH,
            self::MOD_OWNERSHIP_TRANSFER,
            self::MOD_OWNERSHIP_CLEARED,
            // Staff edits and deletions are already flagged on the row by ModeratesMod and the
            // staff tool; without these two the moderation log filtered them straight back out.
            self::MOD_EDIT,
            self::MOD_DELETE,
            self::MOD_CLAIM_VERIFIED,
            self::MOD_CLAIM_REJECTED,
            self::VERSION_DISABLE,
            self::VERSION_ENABLE,
            self::VERSION_PUBLISH,
            self::VERSION_UNPUBLISH,
            self::ADDON_DISABLE,
            self::ADDON_ENABLE,
            self::ADDON_PUBLISH,
            self::ADDON_UNPUBLISH,
            self::ADDON_VERSION_DISABLE,
            self::ADDON_VERSION_ENABLE,
            self::ADDON_VERSION_PUBLISH,
            self::ADDON_VERSION_UNPUBLISH,
            self::COMMENT_PIN,
            self::COMMENT_UNPIN,
            self::COMMENT_SOFT_DELETE,
            self::COMMENT_HARD_DELETE,
            self::COMMENT_RESTORE,
            self::COMMENT_MARK_SPAM,
            self::COMMENT_MARK_CLEAN,
            self::MOD_LIST_DISABLE,
            self::MOD_LIST_ENABLE,
            self::MOD_LIST_DELETE,
            self::USER_EMAIL_CHANGE,
            self::USER_EMAIL_REMOVE,
            self::USER_PASSWORD_RESET_SENT,
            self::USER_PASSWORD_INVALIDATE,
            self::USER_MFA_REMOVE,
            self::USER_DISCORD_UNLINK,
            self::USER_ACCOUNT_LOCK,
            self::USER_PHOTO_REMOVE => true,
            default => false,
        };
    }
}
