<?php

declare(strict_types=1);

namespace App\Actions\Staff;

use App\Models\User;

/**
 * Replace a user's email with an undeliverable placeholder, matching the
 * convention used by account archiving. Returns the address that was replaced.
 *
 * Authorization, auditing and notification are the caller's responsibility.
 */
final class DetachUserEmail
{
    public function execute(User $target, bool $recoverable): string
    {
        $old = $target->email;

        $target->forceFill([
            'email' => ($target->hub_id ?? 'user'.$target->id).'@unclaimed.invalid',
            'email_verified_at' => null,
            'email_tombstone' => $recoverable ? User::emailTombstoneFor($old) : null,
        ])->save();

        return $old;
    }
}
