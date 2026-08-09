<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ModClaimStatus;
use App\Models\ModClaim;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;

/**
 * Expires claims whose token window has passed without proof, so an abandoned claim stops holding its "slot"
 */
#[Timeout(120)]
#[Tries(1)]
final class ExpireStaleModClaimsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $expired = ModClaim::query()
            ->where('status', ModClaimStatus::Pending)
            ->whereNull('escalated_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => ModClaimStatus::Expired]);

        if ($expired > 0) {
            Log::info('ExpireStaleModClaimsJob expired unproven mod claims', ['count' => $expired]);
        }
    }
}
