<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Mod;
use App\Models\ModClaim;
use App\Models\User;

final class ModClaimPolicy
{
    /**
     * Determine whether the user can review claims
     */
    public function viewAny(User $user): bool
    {
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        return $user->isModOrAdmin();
    }

    /**
     * Determine whether the user can claim ownership of a mod, had to be verified, not banned and must have
     * MFA enabled
     */
    public function initiate(User $user, Mod $mod): bool
    {
        if (! $user->hasVerifiedEmail() || $user->isBanned()) {
            return false;
        }

        if (! $user->hasMfaEnabled()) {
            return false;
        }

        if ($mod->owner_id !== null || $mod->disabled) {
            return false;
        }

        return ! $mod->isAuthorOrOwner($user);
    }

    /**
     * Determine whether the user can view a claim
     */
    public function view(User $user, ModClaim $claim): bool
    {
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        if ($user->isModOrAdmin()) {
            return true;
        }

        return $user->id === $claim->user_id;
    }

    /**
     * Determine whether the user can approve or reject a claim.
     */
    public function moderate(User $user, ModClaim $claim): bool
    {
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        return $user->isModOrAdmin();
    }
}
