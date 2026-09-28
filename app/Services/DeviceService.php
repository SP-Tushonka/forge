<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Geolocator;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\DataTransferObjects\DeviceTouch;
use App\Support\UserAgent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;

/**
 * Identifies the browser behind a signed-in request with a random token kept in the forge_device cookie, and keeps
 * one user_devices row per account and browser. Only the token's hash is ever stored.
 */
final readonly class DeviceService
{
    public const string COOKIE = 'forge_device';

    public const string SESSION_KEY = 'device_hash';

    private const int COOKIE_MINUTES = 400 * 24 * 60;

    private const int STATE_TTL_SECONDS = 300;

    public function __construct(private Geolocator $geolocator) {}

    /**
     * The request's device token. A missing or malformed cookie gets a fresh token, held on the request so every
     * caller in the same request sees the same one; it only reaches the browser once touch() queues the cookie.
     */
    public function token(Request $request): string
    {
        $token = $request->attributes->get(self::COOKIE) ?? $request->cookie(self::COOKIE);

        if (! is_string($token) || preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            $token = mb_rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        $request->attributes->set(self::COOKIE, $token);

        return $token;
    }

    public function hash(Request $request): string
    {
        return hash('sha256', $this->token($request));
    }

    /**
     * Record the request against the account's row for this device, creating it if needed. A sign-out is only undone
     * by an interactive login, never by a remember-me reconnection.
     */
    public function touch(User $user, Request $request, bool $interactiveLogin): DeviceTouch
    {
        return $this->touchDevice($user, $request, $interactiveLogin, retried: false);
    }

    /**
     * Tie the request's session to its device, so the session ends if it later arrives with a different device cookie.
     */
    public function bindSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $this->hash($request));
        }
    }

    /**
     * Whether the request carries the device cookie its session was bound to. A session that predates device tracking
     * has no binding yet, and is bound to this request's device.
     */
    public function presentsBoundDevice(Request $request): bool
    {
        if (! $request->hasSession()) {
            return true;
        }

        $bound = $request->session()->get(self::SESSION_KEY);

        if (! is_string($bound)) {
            $this->bindSession($request);

            return true;
        }

        return hash_equals($bound, $this->hash($request));
    }

    /**
     * Whether the account has signed this request's device out. The device row is read and refreshed at most once per
     * STATE_TTL_SECONDS, and created silently for a session that predates device tracking.
     */
    public function isRevoked(User $user, Request $request): bool
    {
        $hash = $this->hash($request);
        $cached = Cache::get(self::stateKey($user->id, $hash));

        if (is_bool($cached)) {
            return $cached;
        }

        $revoked = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('device_hash', $hash)
            ->whereNotNull('revoked_at')
            ->exists();

        if ($revoked) {
            Cache::put(self::stateKey($user->id, $hash), true, self::STATE_TTL_SECONDS);

            return true;
        }

        return $this->touch($user, $request, interactiveLogin: false)->device->revoked_at !== null;
    }

    public function revoke(UserDevice $device): void
    {
        $device->forceFill(['revoked_at' => CarbonImmutable::now()])->save();

        Cache::put(self::stateKey($device->user_id, $device->device_hash), true, self::STATE_TTL_SECONDS);
    }

    /**
     * Sign out every device of the account except the one making this request.
     */
    public function revokeOthers(User $user, Request $request): int
    {
        $devices = $user->devices()
            ->whereNull('revoked_at')
            ->where('device_hash', '!=', $this->hash($request))
            ->get();

        $devices->each(fn (UserDevice $device) => $this->revoke($device));

        return $devices->count();
    }

    private static function stateKey(int $userId, string $hash): string
    {
        return sprintf('user-device:%d:%s', $userId, $hash);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function touchDevice(User $user, Request $request, bool $interactiveLogin, bool $retried): DeviceTouch
    {
        $hash = $this->hash($request);

        $device = UserDevice::query()->firstOrNew(['user_id' => $user->id, 'device_hash' => $hash]);
        $created = ! $device->exists;
        $wasRevoked = $device->revoked_at !== null;

        $userAgent = (string) $request->userAgent();
        $agent = UserAgent::parse($userAgent);
        $ip = (string) $request->ip();
        $location = $ip === '' ? [] : $this->geolocator->getLocationFromIP($ip);
        $now = CarbonImmutable::now();

        $device->forceFill([
            'browser' => $agent['browser'],
            'platform' => $agent['platform'],
            'device_type' => $agent['device_type'],
            'useragent' => $userAgent === '' ? null : mb_substr($userAgent, 0, 512),
            'last_ip' => $ip === '' ? null : $ip,
            'country_code' => self::nullableString($location['country_code'] ?? null),
            'city_name' => self::nullableString($location['city_name'] ?? null),
            'last_seen_at' => $now,
        ]);

        if ($created) {
            $device->first_seen_at = $now;
        }

        if ($interactiveLogin) {
            $device->revoked_at = null;
        }

        try {
            $device->save();
        } catch (UniqueConstraintViolationException $e) {
            if ($retried) {
                throw $e;
            }

            // A concurrent request created the row first; the retry finds and updates it.
            return $this->touchDevice($user, $request, $interactiveLogin, retried: true);
        }

        Cookie::queue(Cookie::make(self::COOKIE, $this->token($request), self::COOKIE_MINUTES, sameSite: 'lax'));
        Cache::put(self::stateKey($user->id, $hash), $device->revoked_at !== null, self::STATE_TTL_SECONDS);

        return new DeviceTouch($device, $created, $wasRevoked);
    }
}
