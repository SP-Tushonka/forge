<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserDevice;

final class UserDevicePolicy
{
    public function update(User $user, UserDevice $device): bool
    {
        return $user->id === $device->user_id;
    }
}
