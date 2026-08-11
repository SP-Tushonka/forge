<?php

declare(strict_types=1);

namespace App\Services\License;

use App\Enums\ClaimVerificationMethod;
use App\Models\User;
use App\Support\ClaimRepositoryUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Proves the custom license option by finding a non empty license file at the root of every repository a mod lists
 */
final readonly class CustomLicenseVerificationService
{
    /**
     * @param  list<string>  $urls  Every source code link on the mod.
     */
    public function passes(array $urls, User $user): bool
    {
        if ($urls === [] || count($urls) > config()->integer('custom-license.max_links', 4)) {
            return false;
        }

        $key = 'custom-license:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, config()->integer('custom-license.max_attempts', 10))) {
            return false;
        }

        RateLimiter::hit($key, config()->integer('custom-license.decay_seconds', 3600));

        return $this->verify($urls);
    }

    /**
     * The same check without the per-user limiter, for the scheduled reaudit
     * 
     * @param  list<string>  $urls  Every source code link on the mod.
     */
    public function passesUnthrottled(array $urls): bool
    {
        if ($urls === [] || count($urls) > config()->integer('custom-license.max_links', 4)) {
            return false;
        }

        return $this->verify($urls);
    }

    /**
     * @param  list<string>  $urls
     */
    private function verify(array $urls): bool
    {
        $deadline = microtime(true) + config()->integer('custom-license.total_timeout', 30);

        foreach ($urls as $url) {
            if (! $this->licensePresentAt($url, $deadline)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check a single repository on any of the configured branches
     */
    private function licensePresentAt(string $url, float $deadline): bool
    {
        $method = ClaimRepositoryUrl::methodFor($url);

        if ($method !== ClaimVerificationMethod::GitHub && $method !== ClaimVerificationMethod::GitLab) {
            return false;
        }

        foreach (ClaimRepositoryUrl::candidates($url, config()->string('custom-license.file_name', 'LICENSE.md')) as $candidate) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            if ($this->hasContent($candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetch a candidate URL and report whether it carries any licence text
     */
    private function hasContent(string $url): bool
    {
        try {
            $response = Http::connectTimeout(config()->integer('custom-license.connect_timeout', 5))
                ->timeout(config()->integer('custom-license.timeout', 15))
                ->withUserAgent(config()->string('verification.user_agent'))
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->get($url);
        } catch (Throwable $throwable) {
            Log::info('Licence file fetch failed', ['url' => $url, 'error' => $throwable->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            return false;
        }

        try {
            $body = $response->toPsrResponse()->getBody()->read(config()->integer('custom-license.max_response_bytes', 65536));
        } catch (Throwable $throwable) {
            Log::info('Licence file read failed', ['url' => $url, 'error' => $throwable->getMessage()]);

            return false;
        }

        return mb_trim($body) !== '';
    }
}
