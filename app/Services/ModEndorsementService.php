<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owns every write to mod_endorsements.
 *
 * The one rule this class exists to enforce: endorsed_at is written exactly once, when a user first endorses a mod,
 * and is never written again. Withdrawing an endorsement sets revoked_at and leaves the row in place, so a later
 * re-endorsement revives that original anchor instead of minting a fresh date. That is what stops a user from
 * cycling their endorsement to keep a mod inside the 7 or 30 day leaderboards.
 *
 * Every write is a conditional single statement rather than a read-then-write, so two concurrent clicks cannot
 * double count. insertOrIgnore is used in place of catching a unique violation: on PostgreSQL a failed statement
 * aborts the surrounding transaction, which in tests is the suite's own wrapping transaction.
 */
final class ModEndorsementService
{
    /**
     * Flip the endorsement state and report where it landed.
     */
    public function toggle(User $user, Mod $mod): bool
    {
        if ($this->isEndorsedBy($user, $mod)) {
            $this->revoke($user, $mod);

            return false;
        }

        $this->endorse($user, $mod);

        return true;
    }

    /**
     * Endorse a mod, reusing the original anchor if this user has endorsed it before.
     *
     * @return bool whether this call is what made the endorsement active
     */
    public function endorse(User $user, Mod $mod): bool
    {
        $now = now();

        $inserted = DB::table('mod_endorsements')->insertOrIgnore([
            'user_id' => $user->id,
            'mod_id' => $mod->id,
            'endorsed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            $mod->incrementEndorsements();

            return true;
        }

        // A row already exists. Revive it only if it is currently withdrawn, and leave endorsed_at untouched.
        $revived = DB::table('mod_endorsements')
            ->where('user_id', $user->id)
            ->where('mod_id', $mod->id)
            ->whereNotNull('revoked_at')
            ->update(['revoked_at' => null, 'updated_at' => $now]);

        if ($revived > 0) {
            $mod->incrementEndorsements();

            return true;
        }

        return false;
    }

    /**
     * Withdraw an endorsement, keeping the row so its anchor survives.
     *
     * @return bool whether this call is what withdrew it
     */
    public function revoke(User $user, Mod $mod): bool
    {
        $now = now();

        $revoked = DB::table('mod_endorsements')
            ->where('user_id', $user->id)
            ->where('mod_id', $mod->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        if ($revoked === 0) {
            return false;
        }

        $mod->decrementEndorsements();

        return true;
    }

    /**
     * Whether this user's endorsement of this mod currently stands.
     */
    public function isEndorsedBy(User $user, Mod $mod): bool
    {
        return ModEndorsement::query()
            ->active()
            ->where('user_id', $user->id)
            ->where('mod_id', $mod->id)
            ->exists();
    }
}
