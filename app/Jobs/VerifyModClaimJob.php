<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ClaimAttemptOutcome;
use App\Enums\ClaimVerificationMethod;
use App\Enums\ModClaimStatus;
use App\Models\ModClaim;
use App\Services\Claim\ModClaimService;
use App\Support\ClaimRepositoryUrl;
use App\Support\DataTransferObjects\ClaimAttempt;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Looks for a claim token in the repositories already linked to a mod and, on a match, hands the mod to the claimant.
 */
#[Timeout(120)]
#[Backoff([5, 15])]
#[Tries(2)]
#[DeleteWhenMissingModels]
final class VerifyModClaimJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public ModClaim $claim) {}

    /**
     * One verification per claim at a time... a retry must not race the attempt already running.
     */
    public function uniqueId(): string
    {
        return 'mod-claim:'.$this->claim->id;
    }

    public function handle(ModClaimService $claims): ClaimAttempt
    {
        $claim = $this->claim->fresh();

        if (! $claim instanceof ModClaim || $claim->status !== ModClaimStatus::Pending) {
            return new ClaimAttempt(ClaimAttemptOutcome::AlreadyOwned, claim: $claim);
        }

        if ($claim->isExpired()) {
            $claim->update(['status' => ModClaimStatus::Expired]);

            return new ClaimAttempt(ClaimAttemptOutcome::Expired, claim: $claim);
        }

        $mod = $claim->mod()->withoutGlobalScopes()->first();

        if ($mod === null || $mod->owner_id !== null) {
            // someone got there first!
            $claims->reject($claim);

            return new ClaimAttempt(ClaimAttemptOutcome::AlreadyOwned, claim: $claim->refresh());
        }

        $candidates = $this->candidates($claim);

        foreach ($candidates as [$method, $url]) {
            if (! $this->tokenPresentAt($url, $claim->token)) {
                continue;
            }

            if ($claims->award($claim, $method, $url)) {
                return new ClaimAttempt(ClaimAttemptOutcome::Verified, claim: $claim->refresh());
            }

            // yet again, someone got there first
            if ($claim->mod()->withoutGlobalScopes()->value('owner_id') === $claim->user_id) {
                return new ClaimAttempt(ClaimAttemptOutcome::Verified, claim: $claim->refresh());
            }

            $claims->reject($claim);

            return new ClaimAttempt(ClaimAttemptOutcome::AlreadyOwned, claim: $claim->refresh());
        }

        return new ClaimAttempt(
            $candidates === [] ? ClaimAttemptOutcome::NoAutomaticSource : ClaimAttemptOutcome::NotFound,
            claim: $claim,
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('VerifyModClaimJob failed', [
            'mod_claim_id' => $this->claim->id,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Tries to match all possible candidates for a claim, basically all sources are checked
     *
     * @return list<array{0: ClaimVerificationMethod, 1: string}>
     */
    private function candidates(ModClaim $claim): array
    {
        $candidates = [];
        $links = 0;
        $limit = config()->integer('claim.max_links', 5);

        foreach ($claim->mod->sourceCodeLinks as $link) {
            if ($links >= $limit) {
                break;
            }

            $method = ClaimRepositoryUrl::methodFor($link->url);

            if ($method === null) {
                continue;
            }

            $links++;

            foreach (ClaimRepositoryUrl::candidates($link->url) as $url) {
                $candidates[] = [$method, $url];
            }
        }

        return $candidates;
    }

    /**
     * Fetch a candidate URL and report whether it carries the token
     */
    private function tokenPresentAt(string $url, string $token): bool
    {
        if (mb_strlen($token) < 16) {
            // A short or empty token would match almost anything.
            return false;
        }

        try {
            $response = Http::connectTimeout(config()->integer('claim.connect_timeout', 5))
                ->timeout(config()->integer('claim.timeout', 15))
                ->withUserAgent(config()->string('verification.user_agent'))
                ->withoutRedirecting()
                ->get($url);
        } catch (Throwable $throwable) {
            Log::info('Claim file fetch failed', ['url' => $url, 'error' => $throwable->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        $body = mb_substr($response->body(), 0, config()->integer('claim.max_response_bytes', 65536));

        return str_contains(mb_trim($body), $token);
    }
}
