<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public bool $renaming = false;

    public ?int $renamingId = null;

    public string $name = '';

    public bool $confirmingSignOutOthers = false;

    public string $password = '';

    /**
     * @return Collection<int, UserDevice>
     */
    #[Computed]
    public function devices(): Collection
    {
        return $this->user()->devices()->whereNull('revoked_at')->latest('last_seen_at')->get();
    }

    #[Computed]
    public function currentHash(): string
    {
        return resolve(DeviceService::class)->hash(request());
    }

    public function startRename(int $deviceId): void
    {
        $device = $this->findDevice($deviceId);

        $this->resetErrorBag();
        $this->renamingId = $device->id;
        $this->name = (string) $device->name;
        $this->renaming = true;
    }

    public function saveName(): void
    {
        $device = $this->findDevice((int) $this->renamingId);

        $this->validate(['name' => ['nullable', 'string', 'max:50']]);

        $name = mb_trim($this->name);
        $device->update(['name' => $name === '' ? null : $name]);

        $this->renaming = false;
        $this->renamingId = null;
        unset($this->devices);

        Flux::toast(heading: 'Device renamed', text: 'The new name is saved.', variant: 'success');
    }

    public function signOut(int $deviceId): void
    {
        $device = $this->findDevice($deviceId);

        if ($device->device_hash === $this->currentHash) {
            return;
        }

        resolve(DeviceService::class)->revoke($device);
        unset($this->devices);

        Flux::toast(heading: 'Device signed out', text: 'That device will be signed out on its next visit.', variant: 'success');
    }

    public function confirmSignOutOthers(): void
    {
        $this->resetErrorBag();
        $this->password = '';
        $this->confirmingSignOutOthers = true;
    }

    public function signOutOthers(): void
    {
        $user = $this->user();

        if ($user->password !== null) {
            if (! Hash::check($this->password, $user->password)) {
                throw ValidationException::withMessages([
                    'password' => [__('This password does not match our records.')],
                ]);
            }

            // Rehashing the password also ends sessions with no device row: AuthenticateSession drops any whose
            // stored password hash is stale. This session gets the new hash below.
            Auth::logoutOtherDevices($this->password);

            if (request()->hasSession()) {
                request()->session()->put([
                    'password_hash_'.Auth::getDefaultDriver() => $user->getAuthPassword(),
                ]);
            }
        }

        $count = resolve(DeviceService::class)->revokeOthers($user, request());

        $this->confirmingSignOutOthers = false;
        $this->password = '';
        unset($this->devices);

        Flux::toast(heading: 'Devices signed out', text: trans_choice('{0} There were no other devices.|{1} 1 other device was signed out.|[2,*] :count other devices were signed out.', $count), variant: 'success');
    }

    private function findDevice(int $deviceId): UserDevice
    {
        $device = UserDevice::query()->whereNull('revoked_at')->findOrFail($deviceId);

        $this->authorize('update', $device);

        return $device;
    }

    private function user(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
};
