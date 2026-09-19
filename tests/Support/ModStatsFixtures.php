<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\TrackingEventType;
use App\Models\ModVersion;
use Illuminate\Support\Facades\DB;

/**
 * Shared mod stats test helpers. Pest test files share one global namespace, so these live on a class rather than as
 * file-level functions that could collide.
 */
final class ModStatsFixtures
{
    /**
     * Record download events the way ModVersionController's tracking would, without the factory's side models.
     */
    public static function download(ModVersion $version, string $at, ?string $country = 'US', int $times = 1): void
    {
        DB::table('tracking_events')->insert(array_fill(0, $times, [
            'event_name' => TrackingEventType::MOD_DOWNLOAD->value,
            'visitable_type' => $version->getMorphClass(),
            'visitable_id' => $version->id,
            'country_code' => $country,
            'is_moderation_action' => false,
            'created_at' => $at,
            'updated_at' => $at,
        ]));
    }

    /**
     * A Cloudflare GraphQL response body carrying the given request groups.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    public static function cloudflareGroups(array $groups): array
    {
        return ['data' => ['viewer' => ['zones' => [['httpRequestsAdaptiveGroups' => $groups]]]]];
    }

    /**
     * One request group of HTML page loads for a path.
     *
     * @return array<string, mixed>
     */
    public static function pageViews(string $path, int $count, string $bot = ''): array
    {
        return ['count' => $count, 'dimensions' => ['clientRequestPath' => $path, 'verifiedBotCategory' => $bot]];
    }
}
