<?php

declare(strict_types=1);

namespace App\Support\DataTransferObjects;

use App\Models\UserDevice;

/**
 * The device row a request was recorded against, and what it looked like before.
 */
final readonly class DeviceTouch
{
    public function __construct(
        public UserDevice $device,
        public bool $created,
        public bool $wasRevoked,
    ) {}
}
