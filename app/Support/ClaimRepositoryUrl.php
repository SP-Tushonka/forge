<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ClaimVerificationMethod;

/**
 * Resolves a stored source code link into the raw URL of its claim file. Basically, looking at the repo
 * and checking if the file is there
 */
final class ClaimRepositoryUrl
{
    /**
     * Build the raw URLs to try for a file at the root of a source code link, in branch order. Returns an empty list
     * when the link is not a repository on an allowlisted host, which routes the claim to manual review.
     *
     * @param  string|null  $fileName  Defaults to the claim file.
     * @return list<string>
     */
    public static function candidates(string $url, ?string $fileName = null): array
    {
        $repository = self::parse($url);

        if ($repository === null) {
            return [];
        }

        [$method, $owner, $repo] = $repository;

        $file = mb_trim($fileName ?? config()->string('claim.file_name', 'claim.txt'), '/');

        // Interpolated into the URL path just like the owner and repo, so it gets the same treatment.
        if (! self::isSafeSegment($file)) {
            return [];
        }

        /** @var list<string> $branches */
        $branches = config('claim.branches', ['main', 'master']);

        return array_values(array_map(
            static fn (string $branch): string => self::rawUrl($method, $owner, $repo, $branch, $file),
            $branches,
        ));
    }

    /**
     * The verification method a link would be proven by, or null when no automated method applies.
     */
    public static function methodFor(string $url): ?ClaimVerificationMethod
    {
        return self::parse($url)[0] ?? null;
    }

    /**
     * Grab the github or gitlab license *grabby hands*
     * 
     * @param  string|null  $fileName  Defaults to the claim file.
     */
    public static function defaultBranchFileUrl(string $url, ?string $fileName = null): ?string
    {
        $repository = self::parse($url);

        if ($repository === null) {
            return null;
        }

        [$method, $owner, $repo] = $repository;

        $file = mb_trim($fileName ?? config()->string('claim.file_name', 'claim.txt'), '/');

        if (! self::isSafeSegment($file)) {
            return null;
        }

        return match ($method) {
            ClaimVerificationMethod::GitHub => sprintf('https://github.com/%s/%s/blob/HEAD/%s', $owner, $repo, $file),
            ClaimVerificationMethod::GitLab => sprintf('https://gitlab.com/%s/%s/-/blob/HEAD/%s', $owner, $repo, $file),
            default => null,
        };
    }

    public static function repository(string $url): ?string
    {
        $repository = self::parse($url);

        return $repository === null ? null : $repository[1].'/'.$repository[2];
    }

    /**
     * Split an allowlisted repository URL into its method, owner, and repository name.
     *
     * @return array{0: ClaimVerificationMethod, 1: string, 2: string}|null
     */
    private static function parse(string $url): ?array
    {
        $parts = parse_url(mb_trim($url));

        if ($parts === false || $parts === null) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        $scheme = mb_strtolower($parts['scheme'] ?? '');
        if ($scheme !== 'https' && $scheme !== 'http') {
            return null;
        }

        $host = mb_strtolower(mb_rtrim($parts['host'] ?? '', '.'));
        $method = self::methodForHost($host);

        if ($method === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $s): bool => $s !== ''));

        if (count($segments) < 2) {
            return null;
        }

        $owner = $segments[0];
        $repo = preg_replace('/\.git$/', '', $segments[1]) ?? '';

        if (! self::isSafeSegment($owner) || ! self::isSafeSegment($repo)) {
            return null;
        }

        return [$method, $owner, $repo];
    }

    /**
     * Match the parsed host against the allowlist by equality
     */
    private static function methodForHost(string $host): ?ClaimVerificationMethod
    {
        /** @var list<string> $allowed */
        $allowed = config('claim.auto_hosts', []);

        if (! in_array($host, array_map(mb_strtolower(...), $allowed), true)) {
            return null;
        }

        return match ($host) {
            'github.com' => ClaimVerificationMethod::GitHub,
            'gitlab.com' => ClaimVerificationMethod::GitLab,
            default => ClaimVerificationMethod::Gitea,
        };
    }

    /**
     * Owner and repository names are interpolated into a URL path, so allow only what those hosts themselves permit
     */
    private static function isSafeSegment(string $segment): bool
    {
        return $segment !== ''
            && $segment !== '.'
            && $segment !== '..'
            && preg_match('/^[A-Za-z0-9._-]+$/', $segment) === 1;
    }

    /**
     * Assemble the raw file URL from a constant host per method
     */
    private static function rawUrl(ClaimVerificationMethod $method, string $owner, string $repo, string $branch, string $file): string
    {
        return match ($method) {
            ClaimVerificationMethod::GitHub => sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s', $owner, $repo, $branch, $file),
            ClaimVerificationMethod::GitLab => sprintf('https://gitlab.com/%s/%s/-/raw/%s/%s', $owner, $repo, $branch, $file),
            ClaimVerificationMethod::Gitea => sprintf('https://codeberg.org/%s/%s/raw/branch/%s/%s', $owner, $repo, $branch, $file),
            ClaimVerificationMethod::Manual => '',
        };
    }
}
