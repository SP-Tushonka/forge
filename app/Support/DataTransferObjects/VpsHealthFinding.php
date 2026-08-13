<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Enums\VpsHealthStatus;

/**
 * One thing the health rules noticed about the host: what it is, the measurement that proves it, and what to do
 * about it. Critical findings are surfaced as immediate actions; warnings are surfaced as recommendations.
 */
final readonly class VpsHealthFinding
{
    public function __construct(
        public VpsHealthStatus $severity,
        public string $title,
        public string $detail,
        public string $action,
    ) {}

    public static function critical(string $title, string $detail, string $action): self
    {
        return new self(VpsHealthStatus::Critical, $title, $detail, $action);
    }

    public static function warning(string $title, string $detail, string $action): self
    {
        return new self(VpsHealthStatus::Warning, $title, $detail, $action);
    }
}
