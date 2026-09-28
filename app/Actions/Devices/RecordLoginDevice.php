<?php

declare(strict_types=1);

namespace App\Actions\Devices;

use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\NewDeviceLoginNotification;
use App\Services\DeviceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Records the device behind a login, binds the session to it, and emails the member when the device is new to an
 * account that already has others, or when an interactive login comes from one they had signed out. A first device
 * and remember-me reconnections of known devices stay silent.
 */
final readonly class RecordLoginDevice
{
    public function __construct(private DeviceService $devices) {}

    public function handle(User $user, Request $request): void
    {
        $interactive = ! Auth::viaRemember();
        $touch = $this->devices->touch($user, $request, interactiveLogin: $interactive);
        $this->devices->bindSession($request);

        $newToAccount = $touch->created && $user->devices()->whereKeyNot($touch->device->id)->exists();

        if (! $newToAccount && ! ($interactive && $touch->wasRevoked)) {
            return;
        }

        // Never the nickname: it is member-set text that the mail's markdown could render as a link.
        $user->notify(new NewDeviceLoginNotification(
            device: UserDevice::describe($touch->device->browser, $touch->device->platform),
            location: $touch->device->location(),
            ip: $touch->device->last_ip,
            signedInAt: CarbonImmutable::now(),
        ));
    }
}
