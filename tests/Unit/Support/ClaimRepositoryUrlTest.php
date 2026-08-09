<?php

declare(strict_types=1);

use App\Enums\ClaimVerificationMethod;
use App\Support\ClaimRepositoryUrl;

describe('ClaimRepositoryUrl', function (): void {
    describe('allowlisted hosts', function (): void {
        it('builds raw candidates for each configured branch', function (): void {
            expect(ClaimRepositoryUrl::candidates('https://github.com/clodanSPT/test', 'tok'))
                ->toBe([
                    'https://raw.githubusercontent.com/clodanSPT/test/main/claim.txt',
                    'https://raw.githubusercontent.com/clodanSPT/test/master/claim.txt',
                ]);
        });

        it('uses the host-specific raw path', function (string $url, string $expected): void {
            expect(ClaimRepositoryUrl::candidates($url, 'tok')[0])->toBe($expected);
        })->with([
            ['https://gitlab.com/owner/repo', 'https://gitlab.com/owner/repo/-/raw/main/claim.txt'],
            ['https://codeberg.org/owner/repo', 'https://codeberg.org/owner/repo/raw/branch/main/claim.txt'],
        ]);

        it('identifies the verification method', function (string $url, ClaimVerificationMethod $method): void {
            expect(ClaimRepositoryUrl::methodFor($url))->toBe($method);
        })->with([
            ['https://github.com/a/b', ClaimVerificationMethod::GitHub],
            ['https://gitlab.com/a/b', ClaimVerificationMethod::GitLab],
            ['https://codeberg.org/a/b', ClaimVerificationMethod::Gitea],
        ]);

        it('tolerates surface variation that does not change the host', function (string $url): void {
            expect(ClaimRepositoryUrl::candidates($url, 'tok'))->not->toBeEmpty();
        })->with([
            'uppercase host' => ['https://GitHub.com/owner/repo'],
            'trailing dot' => ['https://github.com./owner/repo'],
            'trailing slash' => ['https://github.com/owner/repo/'],
            'dot git suffix' => ['https://github.com/owner/repo.git'],
            'deep path' => ['https://github.com/owner/repo/tree/main/src'],
            'surrounding space' => ['  https://github.com/owner/repo  '],
        ]);
    });

    describe('rejected input', function (): void {
        it('returns no candidates, routing the claim to manual review', function (string $url): void {
            expect(ClaimRepositoryUrl::candidates($url, 'tok'))->toBe([])
                ->and(ClaimRepositoryUrl::methodFor($url))->toBeNull();
        })->with([
            // The host reads as allowlisted but resolves elsewhere.
            'credentials in authority' => ['https://github.com@evil.test/owner/repo'],
            'suffix lookalike' => ['https://github.com.evil.test/owner/repo'],
            'prefix lookalike' => ['https://evil-github.com/owner/repo'],
            'subdomain' => ['https://raw.github.com/owner/repo'],
            'substring only' => ['https://mygithub.community/owner/repo'],
            // Software names are not domains: self-hosted instances must not be fetched.
            'self-hosted gitlab' => ['https://gitlab.internal.corp/owner/repo'],
            'self-hosted gitea' => ['https://git.selfhosted-example.net/someone/my-mod'],
            // Non-public or non-web targets.
            'explicit port' => ['https://github.com:8080/owner/repo'],
            'loopback' => ['http://127.0.0.1/owner/repo'],
            'file scheme' => ['file:///etc/passwd'],
            'ssh scheme' => ['git@github.com:owner/repo.git'],
            // Path cannot identify a repository, or tries to climb out of it.
            'no repo segment' => ['https://github.com/owner'],
            'empty path' => ['https://github.com'],
            'traversal' => ['https://github.com/../../etc/passwd'],
            'encoded traversal' => ['https://github.com/owner/%2e%2e%2fetc'],
            'not a url' => ['not a url at all'],
            'empty string' => [''],
        ]);
    });
});
