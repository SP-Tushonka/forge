<?php

declare(strict_types=1);

namespace App\Enums;

enum ModIssueEventType: string
{
    case StatusChanged = 'status_changed';

    case TypeChanged = 'type_changed';

    case FixedVersionChanged = 'fixed_version_changed';

    case Locked = 'locked';

    case Unlocked = 'unlocked';

    case Reopened = 'reopened';

    case FixReleased = 'fix_released';

    /**
     * The sentence the activity list shows after the actor's name.
     */
    public function describe(?string $from, ?string $to, ModIssueType $type): string
    {
        return match ($this) {
            self::StatusChanged => __('changed the status from :from to :to', [
                'from' => ModIssueStatus::tryFrom((string) $from)?->label($type) ?? (string) $from,
                'to' => ModIssueStatus::tryFrom((string) $to)?->label($type) ?? (string) $to,
            ]),
            self::TypeChanged => __('changed the type to :to', [
                'to' => ModIssueType::tryFrom((string) $to)?->label() ?? (string) $to,
            ]),
            self::FixedVersionChanged => $to === null
                ? __('cleared the fixed version')
                : __('set the fixed version to :to', ['to' => $to]),
            self::Locked => __('locked the conversation'),
            self::Unlocked => __('unlocked the conversation'),
            self::Reopened => __('reopened the issue'),
            self::FixReleased => __('released the fix in :to', ['to' => (string) $to]),
        };
    }
}
