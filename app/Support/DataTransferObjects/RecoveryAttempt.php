<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\AccountRecoveryOutcome;
use App\Models\User;

/**
 * Value object representing the result of requesting or completing an archived account recovery.
 */
final readonly class RecoveryAttempt
{
    public function __construct(
        public AccountRecoveryOutcome $outcome,
        public ?int $retryAfterSeconds = null,
        public ?User $user = null,
    ) {}
}
