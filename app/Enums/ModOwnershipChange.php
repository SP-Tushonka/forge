<?php

declare(strict_types=1);

namespace App\Enums;

enum ModOwnershipChange: string
{
    case Transferred = 'transferred';

    case Cleared = 'cleared';

    /**
     * The subject line, which differs for the person losing the mod and the person receiving it.
     */
    public function subject(bool $isNewOwner, string $modName): string
    {
        return match (true) {
            $this === self::Transferred && $isNewOwner => __('You are now the owner of ":mod"', ['mod' => $modName]),
            $this === self::Transferred => __('Ownership of ":mod" was transferred', ['mod' => $modName]),
            default => __('Ownership of ":mod" was removed', ['mod' => $modName]),
        };
    }

    /**
     * The body copy that accompanies the subject.
     */
    public function body(bool $isNewOwner): string
    {
        return match (true) {
            $this === self::Transferred && $isNewOwner => __('A staff member transferred this mod to your account. You can now manage it from your profile.'),
            $this === self::Transferred => __('A staff member transferred this mod to another user. You no longer own it.'),
            default => __('A staff member removed you as the owner of this mod. It is now unowned and may be claimed by someone who can prove ownership.'),
        };
    }
}
