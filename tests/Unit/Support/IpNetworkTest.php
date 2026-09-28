<?php

declare(strict_types=1);

use App\Support\IpNetwork;

it('groups an IPv4 address into its /24', function (): void {
    expect(IpNetwork::rangeOf('84.12.3.77'))->toBe('84.12.3.0/24');
});

it('groups an IPv6 address into its /64 whatever its written form', function (string $ip): void {
    expect(IpNetwork::rangeOf($ip))->toBe('2a02:8108:1a40:3e00::/64');
})->with([
    '2a02:8108:1a40:3e00:1c2d:44ff:fe01:9abc',
    '2A02:8108:1A40:3E00::1',
    '2a02:8108:1a40:3e00:0:0:0:5',
]);

it('groups addresses whose bytes are not valid UTF-8', function (string $ip, string $range): void {
    expect(IpNetwork::rangeOf($ip))->toBe($range);
})->with([
    ['203.0.113.10', '203.0.113.0/24'],
    ['198.51.100.7', '198.51.100.0/24'],
    ['195.160.1.1', '195.160.1.0/24'],
    ['2001:db8:85a3::1', '2001:db8:85a3::/64'],
    ['2a02:c3a0::1', '2a02:c3a0::/64'],
]);

it('keeps zero groups compressed in an IPv6 network', function (): void {
    expect(IpNetwork::rangeOf('2001:db8::1'))->toBe('2001:db8::/64');
});

it('gives no network for values that are not usable addresses', function (string $ip): void {
    expect(IpNetwork::rangeOf($ip))->toBeNull();
})->with(['', 'not-an-ip', '999.1.1.1', '::1']);

it('builds a LIKE prefix that every address in the network starts with', function (string $range, string $prefix): void {
    expect(IpNetwork::likePrefix($range))->toBe($prefix);
})->with([
    ['84.12.3.0/24', '84.12.3.'],
    ['2a02:8108:1a40:3e00::/64', '2a02:8108:1a40:3e00:'],
    ['2001:db8::/64', '2001:db8:'],
]);
