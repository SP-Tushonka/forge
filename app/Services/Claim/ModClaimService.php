<?php

declare(strict_types=1);

namespace App\Services\Claim;

use App\Enums\ClaimAttemptOutcome;
use App\Enums\ClaimVerificationMethod;
use App\Enums\ModClaimStatus;
use App\Models\Mod;
use App\Models\ModClaim;
use App\Models\User;
use App\Support\DataTransferObjects\ClaimAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Creates ownership claims and assigns ownership once one is proven
 *
 * Ownership is only ever granted through {@see self::award()}, which rechecks that the mod is still unowned inside a
 * transaction. Both the automated job and a moderator approval go through it, so two claimants proving themselves at
 * the same moment cannot both win.
 */
final readonly class ModClaimService
{
    /**
     * Start or restart a claim, returning the claim carrying the token to publish.
     */
    public function initiate(Mod $mod, User $user): ClaimAttempt
    {
        if ($mod->owner_id !== null) {
            return new ClaimAttempt(ClaimAttemptOutcome::AlreadyOwned);
        }

        $exempt = $user->isModOrAdmin();
        $key = 'mod-claim:'.$user->id;

        if (! $exempt && RateLimiter::tooManyAttempts($key, config()->integer('claim.max_attempts', 5))) {
            return new ClaimAttempt(ClaimAttemptOutcome::RateLimited, retryAfterSeconds: RateLimiter::availableIn($key));
        }

        $existing = ModClaim::query()
            ->where('mod_id', $mod->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing?->status === ModClaimStatus::Verified) {
            return new ClaimAttempt(ClaimAttemptOutcome::AlreadyOwned, claim: $existing);
        }

        $claim = ModClaim::query()->updateOrCreate(
            ['mod_id' => $mod->id, 'user_id' => $user->id],
            [
                'token' => Str::random(config()->integer('claim.token_length', 32)),
                'status' => ModClaimStatus::Pending,
                'verified_via' => null,
                'verified_url' => null,
                'verified_at' => null,
                'escalated_at' => null,
                'expires_at' => now()->addHours(config()->integer('claim.expiry_hours', 72)),
            ],
        );

        if (! $exempt) {
            RateLimiter::hit($key, config()->integer('claim.decay_seconds', 3600));
        }

        return new ClaimAttempt(ClaimAttemptOutcome::Started, claim: $claim);
    }

    /**
     * Hand the mod to the claimant, checking first if someone already claimed it ofc
     */
    public function award(ModClaim $claim, ClaimVerificationMethod $method, ?string $verifiedUrl = null): bool
    {
        return DB::transaction(function () use ($claim, $method, $verifiedUrl): bool {
            $assigned = Mod::withoutGlobalScopes()
                ->whereKey($claim->mod_id)
                ->whereNull('owner_id')
                ->update(['owner_id' => $claim->user_id]);

            if ($assigned === 0) {
                return false;
            }

            $claim->update([
                'status' => ModClaimStatus::Verified,
                'verified_via' => $method,
                'verified_url' => $verifiedUrl,
                'verified_at' => now(),
            ]);

            ModClaim::query()
                ->where('mod_id', $claim->mod_id)
                ->whereKeyNot($claim->id)
                ->where('status', ModClaimStatus::Pending)
                ->update(['status' => ModClaimStatus::Rejected]);

            return true;
        });
    }

    /**
     * Put a claim in front of a moderator. Escalated claims are exempt from expiry: they wait on staff, not the
     * claimant
     */
    public function escalate(ModClaim $claim): ClaimAttempt
    {
        if ($claim->escalated_at === null) {
            $claim->update(['escalated_at' => now()]);
        }

        return new ClaimAttempt(ClaimAttemptOutcome::Escalated, claim: $claim->refresh());
    }

    /**
     * Reject a claim.
     */
    public function reject(ModClaim $claim): void
    {
        $claim->update(['status' => ModClaimStatus::Rejected]);
    }
}
