<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Enums\EmojiSurface;
use App\Models\Emoji;
use App\Models\Mod;
use App\Services\ReactionSummaryService;
use App\Support\DataTransferObjects\ReactionSummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Read-only reaction data for a Livewire component.
 *
 * Deliberately separate from HandlesReactions: listing surfaces show a summarised figure but must not accept a
 * reaction, so they take this trait and never gain a toggleReaction() endpoint at all. Only the surfaces where
 * reacting is allowed pull in HandlesReactions, which builds on this.
 *
 * Hosts supply only reactableIds() - what is on screen this render - and the computed properties the views need are
 * provided here, so the surfaces that render reactions do not each repeat them.
 */
trait ProvidesReactionSummary
{
    /**
     * Ids of the reactables the current render displays, kept across requests so a toggle can rebuild the summary.
     *
     * @var list<int>
     */
    #[Locked]
    public array $renderedReactableIds = [];

    /**
     * Reaction counts and the viewer's own picks for everything on screen. Two queries per render regardless of how
     * many reactables there are.
     */
    #[Computed]
    public function reactionSummary(): ReactionSummary
    {
        return resolve(ReactionSummaryService::class)
            ->for($this->reactableClass(), $this->reactableIds(), Auth::user());
    }

    /**
     * The emoji this surface is allowed to show, shared by everything on the page that renders a reaction.
     *
     * Filtering here rather than in each view is what makes a restriction affect the totals as well as the icons:
     * both the bar and the card summary derive their counts from this collection, so an emoji missing from it
     * contributes nothing to either.
     *
     * @return SupportCollection<int, Emoji>
     */
    #[Computed]
    public function reactionWhitelist(): SupportCollection
    {
        return resolve(ReactionSummaryService::class)->whitelistFor($this->reactionSurface());
    }

    /**
     * Where this component's reactions appear. Mod surfaces are the majority and inherit this; the comment thread
     * overrides it.
     *
     * The summarised figure on mod cards deliberately shares the mod reaction permission rather than having one of
     * its own: the card total is a rollup of those reactions, so an emoji kept off mod reactions should not go on
     * contributing to the number shown for them.
     */
    protected function reactionSurface(): EmojiSurface
    {
        return EmojiSurface::ModReactions;
    }

    /**
     * Record what this render put on screen, so reactableIds() can answer without repeating the page's own query.
     * Pages that build their collection inside with() call this from there.
     *
     * @param  iterable<Model>  $reactables
     */
    protected function rememberReactableIds(iterable $reactables): void
    {
        $ids = [];

        foreach ($reactables as $reactable) {
            $key = $reactable->getKey();

            if (! is_numeric($key)) {
                continue;
            }

            $ids[] = (int) $key;
        }

        $this->renderedReactableIds = array_values(array_unique($ids));

        unset($this->reactionSummary);
    }

    /**
     * Ids of the reactables this render displays. Components with a computed collection override this; pages that
     * build theirs in with() call rememberReactableIds() instead and leave this alone.
     *
     * @return list<int>
     */
    protected function reactableIds(): array
    {
        return $this->renderedReactableIds;
    }

    /**
     * The model the ids from reactableIds() belong to.
     *
     * @return class-string<Model>
     */
    protected function reactableClass(): string
    {
        return Mod::class;
    }
}
