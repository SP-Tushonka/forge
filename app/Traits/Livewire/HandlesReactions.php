<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Contracts\Reactable;
use App\Enums\EmojiSurface;
use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\Comment;
use App\Models\Emoji;
use App\Models\Mod;
use App\Models\Reaction;
use App\Models\User;
use App\Services\ReactionSummaryService;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Adds reaction toggling to a Livewire component, on top of the read-only data ProvidesReactionSummary supplies.
 *
 * Only surfaces where reacting is permitted should use this trait; a listing that merely displays a summary takes
 * ProvidesReactionSummary alone and therefore has no toggleReaction() endpoint to call. The blade passes a short key
 * rather than a class name, so a crafted payload cannot reach an arbitrary model either.
 */
trait HandlesReactions
{
    use ProvidesReactionSummary;

    public function toggleReaction(string $reactableType, int $reactableId, int $emojiId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        $class = $this->resolveReactableClass($reactableType);

        if ($class === null) {
            abort(404);
        }

        // Filtered by surface, not merely by the whitelist: the picker already hides an emoji staff restricted here,
        // and checking the same list the picker was built from is what stops a crafted payload using one anyway.
        $emoji = resolve(ReactionSummaryService::class)
            ->whitelistFor($this->resolveReactableSurface($reactableType))
            ->firstWhere('id', $emojiId);

        if (! $emoji instanceof Emoji) {
            return;
        }

        $reactable = $class::query()->find($reactableId);

        if (! $reactable instanceof Reactable || ! $reactable->canReceiveReactions()) {
            return;
        }

        $this->guardReactable($reactable);

        $response = Gate::inspect('react', $reactable);

        if ($response->denied()) {
            Flux::toast(
                heading: __('Cannot react'),
                text: $response->message() ?? __('You cannot react to this.'),
                variant: 'danger',
            );

            return;
        }

        if (! $this->withinReactionRateLimit($user)) {
            return;
        }

        $this->applyReactionToggle($user, $reactable, $emoji);
        $this->afterReactionToggled($reactable);
    }

    /**
     * An extra model-specific check before the policy runs. The comment component uses it to confirm the comment
     * actually belongs to the commentable it is rendering.
     */
    protected function guardReactable(Model $reactable): void
    {
        //
    }

    /**
     * Called after a successful toggle so hosts can refresh their own caches. Overrides should call parent behaviour
     * by unsetting the summary themselves, or simply not override this.
     */
    protected function afterReactionToggled(Model $reactable): void
    {
        unset($this->reactionSummary);
    }

    /**
     * Map the short key the blade sends onto a model class. Anything unrecognised resolves to null, so a crafted
     * payload cannot reach an arbitrary model.
     *
     * @return class-string<Model>|null
     */
    private function resolveReactableClass(string $key): ?string
    {
        return match ($key) {
            'comment' => Comment::class,
            'mod' => Mod::class,
            default => null,
        };
    }

    /**
     * The surface a reaction is being left on. Only the two interactive surfaces can be reached here; the summarised
     * figure on mod cards is read-only and has no toggle endpoint at all.
     */
    private function resolveReactableSurface(string $key): EmojiSurface
    {
        return match ($key) {
            'comment' => EmojiSurface::CommentReactions,
            default => EmojiSurface::ModReactions,
        };
    }

    /**
     * A user holds at most one reaction per item. Clicking the emoji they already picked removes it; clicking a
     * different one replaces their choice rather than adding to it.
     */
    private function applyReactionToggle(User $user, Model&Reactable $reactable, Emoji $emoji): void
    {
        /** @var Reaction|null $existing */
        $existing = $reactable->reactions()
            ->where('user_id', $user->id)
            ->first();

        if ($existing instanceof Reaction) {
            if ($existing->emoji_id === $emoji->id) {
                $existing->delete();
                Track::event($this->reactionTrackingType($reactable, removed: true), $reactable);

                return;
            }

            $existing->update(['emoji_id' => $emoji->id]);
            Track::event($this->reactionTrackingType($reactable, removed: false), $reactable);

            return;
        }

        $reactable->reactions()->create(['user_id' => $user->id, 'emoji_id' => $emoji->id]);
        Track::event($this->reactionTrackingType($reactable, removed: false), $reactable);
    }

    /**
     * Comments keep the existing COMMENT_LIKE/COMMENT_UNLIKE vocabulary: renaming those would orphan the tracking rows
     * already in production.
     */
    private function reactionTrackingType(Model $reactable, bool $removed): TrackingEventType
    {
        if ($reactable instanceof Comment) {
            return $removed ? TrackingEventType::COMMENT_UNLIKE : TrackingEventType::COMMENT_LIKE;
        }

        return $removed ? TrackingEventType::MOD_UNREACT : TrackingEventType::MOD_REACT;
    }

    private function withinReactionRateLimit(User $user): bool
    {
        if ($user->isModOrAdmin()) {
            return true;
        }

        $key = 'reactions:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, config()->integer('reactions.rate_limiting.max_attempts', 30))) {
            Flux::toast(
                heading: __('Slow down'),
                text: __('You are reacting too quickly. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
                variant: 'danger',
            );

            return false;
        }

        RateLimiter::hit($key, config()->integer('reactions.rate_limiting.duration_seconds', 60));

        return true;
    }
}
