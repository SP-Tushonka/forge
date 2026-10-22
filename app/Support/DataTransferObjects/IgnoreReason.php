<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Models\Mod;
use App\Models\User;

/**
 * Why a page is hidden from its viewer: the mod itself is ignored, or it belongs to an ignored user.
 */
final readonly class IgnoreReason
{
    public function __construct(
        public ?Mod $mod = null,
        public ?User $user = null,
    ) {}
}
