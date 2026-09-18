<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\EmojiSurface;
use App\Models\Emoji;
use App\Models\Reaction;
use App\Models\User;
use App\Support\DataTransferObjects\ReactionSummary;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds reaction data for a whole page in two queries, so cards never load their own. The alternative — a component
 * per card resolving its own counts — is what makes ribbon.mod issue one query per card on every listing page.
 */
final class ReactionSummaryService
{
    /**
     * Versioned so a payload cached under an older shape is ignored rather than unserialized into the new one.
     *
     * Bump this whenever a column is added to `emojis`. Rows cached under the old key carry the old column set, and
     * hydrating them produces models missing the new attributes - which strict mode reports as a
     * MissingAttributeException on the next page load, for as long as the entry has left to live.
     */
    public const string WHITELIST_CACHE_KEY = 'reactions.whitelist.v6';

    private const int WHITELIST_CACHE_SECONDS = 3600;

    /**
     * The emoji currently on offer. Cached because it changes only when staff edit it, and every page needs it.
     *
     * Raw attribute rows are cached, never Eloquent models. A real cache driver serializes what it stores, and a
     * serialized model that cannot be resolved on the way back out returns __PHP_Incomplete_Class. Caching plain
     * arrays also matches HomepageSectionCache, which stores ids rather than models for the same reason.
     *
     * @return Collection<int, Emoji>
     */
    public function whitelist(): Collection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            self::WHITELIST_CACHE_KEY,
            self::WHITELIST_CACHE_SECONDS,
            fn (): array => Emoji::query()->enabled()->get()
                ->map(static fn (Emoji $emoji): array => $emoji->getAttributes())
                ->values()
                ->all(),
        );

        return Emoji::hydrate($rows);
    }

    /**
     * The emoji on offer for one surface. Filtered in PHP from the already-cached whitelist rather than queried
     * separately: the full list is small, every page loads it anyway, and a cache entry per surface would be three
     * things to invalidate instead of one.
     *
     * @return Collection<int, Emoji>
     */
    public function whitelistFor(EmojiSurface $surface): Collection
    {
        return $this->whitelist()->filter(
            static fn (Emoji $emoji): bool => $emoji->allowsOn($surface),
        )->values();
    }

    /**
     * Drop the cached whitelist. Called by the staff tool after any edit.
     */
    public function forgetWhitelist(): void
    {
        Cache::forget(self::WHITELIST_CACHE_KEY);
    }

    /**
     * @param  list<int>  $reactableIds
     */
    public function for(string $reactableType, array $reactableIds, ?User $user): ReactionSummary
    {
        if ($reactableIds === []) {
            return new ReactionSummary();
        }

        return new ReactionSummary(
            counts: $this->counts($reactableType, $reactableIds),
            mine: $user instanceof User ? $this->mine($reactableType, $reactableIds, $user) : [],
        );
    }

    /**
     * @param  list<int>  $reactableIds
     * @return array<int, array<int, int>>
     */
    private function counts(string $reactableType, array $reactableIds): array
    {
        $rows = Reaction::query()
            ->select('reactable_id', 'emoji_id', DB::raw('count(*) as aggregate'))
            ->where('reactable_type', $reactableType)
            ->whereIn('reactable_id', $reactableIds)
            ->groupBy('reactable_id', 'emoji_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            // pgsql returns count(*) as a string and sqlite as an int, so the aggregate is genuinely untyped here.
            $aggregate = $row->getAttribute('aggregate');

            if (! is_numeric($aggregate)) {
                continue;
            }

            $counts[$row->reactable_id][$row->emoji_id] = (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $reactableIds
     * @return array<int, list<int>>
     */
    private function mine(string $reactableType, array $reactableIds, User $user): array
    {
        $rows = Reaction::query()
            ->select('reactable_id', 'emoji_id')
            ->where('reactable_type', $reactableType)
            ->whereIn('reactable_id', $reactableIds)
            ->where('user_id', $user->id)
            ->get();

        $mine = [];

        foreach ($rows as $row) {
            $mine[$row->reactable_id][] = $row->emoji_id;
        }

        return $mine;
    }
}
