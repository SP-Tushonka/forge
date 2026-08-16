<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Recognises requests a browser issued speculatively — Cloudflare's Speed Brain, a `<link rel="prefetch">`, or any
 * other speculation rules prefetch — rather than because a visitor acted.
 */
final class SpeculativeRequest
{
    /**
     * Headers that mark a speculative fetch, mapped to the values that qualify. `Sec-Purpose` is the current standard
     * and carries a token list (`prefetch`, `prefetch;prerender`), so it is matched as a substring; the others are
     * single-value headers predating the speculation rules API, kept for older browsers.
     *
     * @var array<string, list<string>>
     */
    private const array SPECULATION_HEADERS = [
        'Sec-Purpose' => ['prefetch', 'prerender'],
        'Purpose' => ['prefetch'],
        'X-Moz' => ['prefetch'],
        'X-Purpose' => ['preview'],
    ];

    /**
     * Whether the browser fetched this speculatively rather than because the visitor asked for it.
     */
    public static function isPrefetch(Request $request): bool
    {
        foreach (self::SPECULATION_HEADERS as $header => $tokens) {
            $value = $request->header($header);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $value = mb_strtolower($value);

            foreach ($tokens as $token) {
                if (str_contains($value, $token)) {
                    return true;
                }
            }
        }

        return false;
    }
}
