<?php

declare(strict_types=1);

use App\Support\UserAgent;

it('parses a desktop browser', function (): void {
    $parsed = UserAgent::parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36');

    expect($parsed)->toBe(['browser' => 'Chrome', 'platform' => 'Windows', 'device_type' => 'desktop']);
});

it('parses a phone', function (): void {
    $parsed = UserAgent::parse('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1');

    expect($parsed['device_type'])->toBe('mobile')
        ->and($parsed['browser'])->toBe('Safari');
});

it('returns nulls for an empty user agent', function (): void {
    expect(UserAgent::parse(''))->toBe(['browser' => null, 'platform' => null, 'device_type' => 'desktop']);
});
