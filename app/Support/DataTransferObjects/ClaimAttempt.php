<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\ClaimAttemptOutcome;
use App\Models\ModClaim;

/**
 * Value object representing the result of initiating or verifying a mod claim.
 */
final readonly class ClaimAttempt
{
    public function __construct(
        public ClaimAttemptOutcome $outcome,
        public ?int $retryAfterSeconds = null,
        public ?ModClaim $claim = null,
    ) {}
}
