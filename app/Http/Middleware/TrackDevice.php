<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the signed-in user's device row current, and ends the session on a device the user has signed out or when it
 * arrives with a different device cookie than the one it was bound to.
 */
final readonly class TrackDevice
{
    public function __construct(private DeviceService $devices) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->hasSession() ? $request->user() : null;

        if ($user instanceof User && $this->signedOut($user, $request)) {
            Auth::logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Livewire update requests (POST /livewire/update) in the web group receive a 419 to trigger
            // Livewire's page-expired handler (page reload), which then requests as a guest.
            if ($request->hasHeader('X-Livewire')) {
                abort(419);
            }

            return redirect()->route('login');
        }

        return $next($request);
    }

    /**
     * The binding is checked first: isRevoked() records an unknown device as a new, active one, which would let a
     * signed-out session escape by dropping its device cookie.
     */
    private function signedOut(User $user, Request $request): bool
    {
        return ! $this->devices->presentsBoundDevice($request) || $this->devices->isRevoked($user, $request);
    }
}
