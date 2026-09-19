<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Reads edge-side request analytics from the Cloudflare GraphQL Analytics API: the open API's cached/origin split, the
 * recent-visitor count, and per-mod page views for the mod stats dashboard.
 */
final class CloudflareAnalyticsService
{
    /**
     * The Cloudflare GraphQL Analytics endpoint.
     */
    private const string GRAPHQL_ENDPOINT = 'https://api.cloudflare.com/client/v4/graphql';

    /**
     * Cache statuses that Cloudflare served from its edge without a full origin fetch, mirroring the "Cached" bucket on
     * the Cloudflare dashboard. Everything else (miss, expired, dynamic, bypass, none, ...) is treated as origin traffic.
     *
     * @var list<string>
     */
    private const array CACHED_STATUSES = ['hit', 'stale', 'revalidated', 'updating'];

    /**
     * How many trailing minutes of page loads count towards the online figure.
     */
    private const int VISITOR_WINDOW_MINUTES = 5;

    /**
     * How far behind the present the visitor window ends.
     */
    private const int VISITOR_LAG_MINUTES = 3;

    /**
     * The most distinct addresses the visitor query will return.
     */
    private const int VISITOR_ROW_LIMIT = 10000;

    /**
     * The most request groups a mod page view query returns; a full response means the window must be split.
     */
    private const int MOD_VIEWS_ROW_LIMIT = 10000;

    /**
     * The smallest window a full mod page view response is split down to before it is accepted as a lower bound.
     */
    private const int MOD_VIEWS_MIN_WINDOW_SECONDS = 3600;

    /**
     * Mod detail page paths, /mod/{id}/{slug} with an optional trailing slash. The edit page shares that shape.
     */
    private const string MOD_PAGE_PATTERN = '#^/mod/(\d+)/(?!edit/?$)[^/]+/?$#';

    /**
     * The edge request totals for the open API over the trailing 24 hours, or null when Cloudflare analytics are not
     * configured or the request fails.
     *
     * @return array{edge_total: int, cached: int, origin: int, cached_pct: float}|null
     */
    public function apiUsageLast24Hours(): ?array
    {
        $token = config('services.cloudflare.analytics_token');
        $zoneId = config('services.cloudflare.zone_id');

        if (! is_string($token) || $token === '' || ! is_string($zoneId) || $zoneId === '') {
            return null;
        }

        $host = $this->apiHost();

        if ($host === '') {
            return null;
        }

        $until = now()->utc();
        $since = $until->subDay();
        $prefix = config('services.cloudflare.api_path_prefix', '/api/');
        $pathFilter = (is_string($prefix) ? $prefix : '/api/').'%';

        $groups = $this->requestGroups($token, $this->query(), [
            'zone' => $zoneId,
            'since' => $since->toIso8601ZuluString(),
            'until' => $until->toIso8601ZuluString(),
            'host' => $host,
            'path' => $pathFilter,
        ]);

        if ($groups === null) {
            return null;
        }

        /** @var array<int, array{count?: int, dimensions?: array{cacheStatus?: string}}> $groups */
        return $this->summarize($groups);
    }

    /**
     * The number of distinct client addresses served an HTML response over the trailing window, or null when Cloudflare
     * analytics are not configured or the request fails.
     *
     * @return array{count: int, window_minutes: int}|null
     */
    public function visitorsOnline(): ?array
    {
        $token = config('services.cloudflare.analytics_token');
        $zoneId = config('services.cloudflare.zone_id');

        if (! is_string($token) || $token === '' || ! is_string($zoneId) || $zoneId === '') {
            return null;
        }

        $host = $this->apiHost();

        if ($host === '') {
            return null;
        }

        $until = now()->utc()->subMinutes(self::VISITOR_LAG_MINUTES);
        $since = $until->subMinutes(self::VISITOR_WINDOW_MINUTES);

        $groups = $this->requestGroups($token, $this->visitorQuery(), [
            'zone' => $zoneId,
            'since' => $since->toIso8601ZuluString(),
            'until' => $until->toIso8601ZuluString(),
            'host' => $host,
        ]);

        if ($groups === null) {
            return null;
        }

        $count = count($groups);

        if ($count >= self::VISITOR_ROW_LIMIT) {
            Log::warning('Cloudflare visitor query hit its row limit; the online count is a lower bound', [
                'limit' => self::VISITOR_ROW_LIMIT,
            ]);
        }

        return [
            'count' => $count,
            'window_minutes' => self::VISITOR_WINDOW_MINUTES,
        ];
    }

    /**
     * HTML loads of mod pages per mod ID over [since, until), excluding Cloudflare-verified bots, or null when Cloudflare
     * analytics are not configured or any request fails. Callers must treat null as unknown, never as zero views.
     *
     * @return array<int, int>|null
     */
    public function modPageViews(CarbonImmutable $since, CarbonImmutable $until): ?array
    {
        $token = config('services.cloudflare.analytics_token');
        $zoneId = config('services.cloudflare.zone_id');

        if (! is_string($token) || $token === '' || ! is_string($zoneId) || $zoneId === '') {
            return null;
        }

        $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $views = [];

        return $this->collectModPageViews($token, $zoneId, $host, $since, $until, $views) ? $views : null;
    }

    /**
     * Post a GraphQL query and return the zone's request groups, or null when the call fails in any way.
     *
     * @param  array<string, string>  $variables
     * @return array<int, array<string, mixed>>|null
     */
    private function requestGroups(string $token, string $query, array $variables): ?array
    {
        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->retry(3, 100, throw: false)
                ->withUserAgent(Str::slug(config()->string('app.name').'-'.config()->string('app.env')))
                ->withToken($token)
                ->post(self::GRAPHQL_ENDPOINT, [
                    'query' => $query,
                    'variables' => $variables,
                ]);
        } catch (ConnectionException $connectionException) {
            Log::warning('Cloudflare analytics request failed', ['error' => $connectionException->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Cloudflare analytics request returned a non-success status', ['status' => $response->status()]);

            return null;
        }

        /** @var array<int, array{errors?: array<int, array<string, mixed>>}>|null $errors */
        $errors = $response->json('errors');
        if (! empty($errors)) {
            Log::warning('Cloudflare analytics query returned errors', ['errors' => $errors]);

            return null;
        }

        /** @var array<int, array<string, mixed>>|null $groups */
        $groups = $response->json('data.viewer.zones.0.httpRequestsAdaptiveGroups');
        if ($groups === null) {
            Log::warning('Cloudflare analytics response was missing the expected request groups');

            return null;
        }

        return $groups;
    }

    /**
     * The hostname whose edge requests are counted: the configured API host, or the application URL's host.
     */
    private function apiHost(): string
    {
        $host = config('services.cloudflare.api_host');

        if (is_string($host) && $host !== '') {
            return $host;
        }

        $appHost = parse_url(config()->string('app.url'), PHP_URL_HOST);

        return is_string($appHost) ? $appHost : '';
    }

    /**
     * Reduce the per-cache-status groups into cached / origin totals. Each group's count arrives from Cloudflare
     * already adjusted for sampling and must not be scaled by its sampleInterval.
     *
     * @param  array<int, array{count?: int, dimensions?: array{cacheStatus?: string}}>  $groups
     * @return array{edge_total: int, cached: int, origin: int, cached_pct: float}
     */
    private function summarize(array $groups): array
    {
        $cached = 0;
        $origin = 0;

        foreach ($groups as $group) {
            $count = $group['count'] ?? 0;
            $status = Str::lower($group['dimensions']['cacheStatus'] ?? '');

            if (in_array($status, self::CACHED_STATUSES, true)) {
                $cached += $count;
            } else {
                $origin += $count;
            }
        }

        $edgeTotal = $cached + $origin;

        return [
            'edge_total' => $edgeTotal,
            'cached' => $cached,
            'origin' => $origin,
            'cached_pct' => $edgeTotal > 0 ? round($cached / $edgeTotal * 100, 1) : 0.0,
        ];
    }

    /**
     * The GraphQL query that groups the API hostname's requests by cache status over a datetime window.
     */
    private function query(): string
    {
        return <<<'GRAPHQL'
        query ($zone: String!, $since: Time!, $until: Time!, $host: String!, $path: String!) {
            viewer {
                zones(filter: { zoneTag: $zone }) {
                    httpRequestsAdaptiveGroups(
                        limit: 50,
                        filter: { datetime_geq: $since, datetime_leq: $until, clientRequestHTTPHost: $host, clientRequestPath_like: $path }
                    ) {
                        count
                        dimensions { cacheStatus }
                    }
                }
            }
        }
        GRAPHQL;
    }

    /**
     * The GraphQL query returning one row per distinct client address served an HTML response by the site's hostname.
     */
    private function visitorQuery(): string
    {
        return <<<GRAPHQL
        query (\$zone: String!, \$since: Time!, \$until: Time!, \$host: String!) {
            viewer {
                zones(filter: { zoneTag: \$zone }) {
                    httpRequestsAdaptiveGroups(
                        limit: {$this->visitorRowLimit()},
                        filter: { datetime_geq: \$since, datetime_lt: \$until, clientRequestHTTPHost: \$host, edgeResponseContentTypeName: "html" }
                    ) {
                        dimensions { clientIP }
                    }
                }
            }
        }
        GRAPHQL;
    }

    /**
     * The visitor query's row limit.
     */
    private function visitorRowLimit(): int
    {
        return self::VISITOR_ROW_LIMIT;
    }

    /**
     * Add the window's mod page views to $views, halving the window whenever a response is full.
     *
     * @param  array<int, int>  $views
     */
    private function collectModPageViews(string $token, string $zoneId, string $host, CarbonImmutable $since, CarbonImmutable $until, array &$views): bool
    {
        $groups = $this->requestGroups($token, $this->modPageViewsQuery(), [
            'zone' => $zoneId,
            'since' => $since->toIso8601ZuluString(),
            'until' => $until->toIso8601ZuluString(),
            'host' => $host,
        ]);

        if ($groups === null) {
            return false;
        }

        if (count($groups) >= self::MOD_VIEWS_ROW_LIMIT) {
            $seconds = (int) $since->diffInSeconds($until);

            if ($seconds > self::MOD_VIEWS_MIN_WINDOW_SECONDS) {
                $middle = $since->addSeconds(intdiv($seconds, 2));

                return $this->collectModPageViews($token, $zoneId, $host, $since, $middle, $views)
                    && $this->collectModPageViews($token, $zoneId, $host, $middle, $until, $views);
            }

            Log::warning('Cloudflare mod page view query hit its row limit on the smallest window; views are a lower bound', [
                'since' => $since->toIso8601ZuluString(),
                'until' => $until->toIso8601ZuluString(),
            ]);
        }

        foreach ($groups as $group) {
            $path = data_get($group, 'dimensions.clientRequestPath');
            $bot = data_get($group, 'dimensions.verifiedBotCategory');
            $count = data_get($group, 'count');

            if (! is_string($path) || ! is_int($count) || (is_string($bot) && $bot !== '')) {
                continue;
            }

            if (preg_match(self::MOD_PAGE_PATTERN, $path, $matches) !== 1) {
                continue;
            }

            $modId = (int) $matches[1];
            $views[$modId] = ($views[$modId] ?? 0) + $count;
        }

        return true;
    }

    /**
     * The GraphQL query returning mod-page HTML loads (GET, 200) grouped by path and verified-bot category.
     */
    private function modPageViewsQuery(): string
    {
        return <<<GRAPHQL
        query (\$zone: String!, \$since: Time!, \$until: Time!, \$host: String!) {
            viewer {
                zones(filter: { zoneTag: \$zone }) {
                    httpRequestsAdaptiveGroups(
                        limit: {$this->modViewsRowLimit()},
                        filter: {
                            datetime_geq: \$since, datetime_lt: \$until, clientRequestHTTPHost: \$host,
                            clientRequestPath_like: "/mod/%", clientRequestHTTPMethodName: "GET",
                            edgeResponseContentTypeName: "html", edgeResponseStatus: 200
                        }
                    ) {
                        count
                        dimensions { clientRequestPath verifiedBotCategory }
                    }
                }
            }
        }
        GRAPHQL;
    }

    /**
     * The mod page view query's row limit.
     */
    private function modViewsRowLimit(): int
    {
        return self::MOD_VIEWS_ROW_LIMIT;
    }
}
