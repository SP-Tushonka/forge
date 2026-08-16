<?php

declare(strict_types=1);

use App\Support\SpeculativeRequest;
use Illuminate\Http\Request;

/**
 * Build a request carrying a single header, mirroring how a browser announces a speculative fetch.
 */
function requestWithHeader(string $header, string $value): Request
{
    $request = Request::create('/mod/download/1/example/1.0.0');
    $request->headers->set($header, $value);

    return $request;
}

describe('isPrefetch', function (): void {
    it('treats an ordinary request as a real one', function (): void {
        expect(SpeculativeRequest::isPrefetch(Request::create('/mod/download/1/example/1.0.0')))->toBeFalse();
    });

    it('recognises every speculation header browsers send', function (string $header, string $value): void {
        expect(SpeculativeRequest::isPrefetch(requestWithHeader($header, $value)))->toBeTrue();
    })->with([
        'speculation rules prefetch' => ['Sec-Purpose', 'prefetch'],
        'speculation rules prerender' => ['Sec-Purpose', 'prefetch;prerender'],
        'anonymised prefetch proxy' => ['Sec-Purpose', 'prefetch;anonymous-client-ip'],
        'legacy chrome' => ['Purpose', 'prefetch'],
        'legacy firefox' => ['X-Moz', 'prefetch'],
        'safari preview' => ['X-Purpose', 'preview'],
    ]);

    it('matches case insensitively', function (): void {
        expect(SpeculativeRequest::isPrefetch(requestWithHeader('Sec-Purpose', 'PREFETCH')))->toBeTrue();
    });

    it('ignores a speculation header sent with an unrelated value', function (): void {
        expect(SpeculativeRequest::isPrefetch(requestWithHeader('Sec-Purpose', 'navigate')))->toBeFalse();
    });

    it('ignores an empty speculation header', function (): void {
        expect(SpeculativeRequest::isPrefetch(requestWithHeader('Sec-Purpose', '')))->toBeFalse();
    });
});
