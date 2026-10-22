<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Addon;
use App\Models\Comment;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModList;
use App\Models\ModVersion;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Support\IgnoredContent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * The user whose activity is being displayed.
     */
    public User $user;

    /**
     * Livewire lifecycle method: Initialize the component with a user.
     *
     * This magic method is automatically called when the component is first mounted.
     * It receives the User model passed from the parent view.
     */
    public function mount(User $user): void
    {
        $this->user = $user;
    }

    /**
     * Get the user's recent activity events.
     *
     * @return Collection<int, TrackingEvent>
     */
    #[Computed]
    public function recentActivity(): Collection
    {
        $events = TrackingEvent::query()
            // Get all events where the user is the visitor (simplified since logout now has visitor_id)
            ->where('visitor_id', $this->user->id)
            ->with('visitable')
            ->latest()
            ->limit(15)
            ->get();

        // Eagerly load the mod relationship without global scopes for ModVersion visitables only
        $events->each(function (TrackingEvent $event): void {
            if ($event->visitable instanceof ModVersion && ! $event->visitable->relationLoaded('mod')) {
                $event->visitable->loadMissing([
                    'mod' => function (BelongsTo $relation): void {
                        $relation->getQuery()->withoutGlobalScopes();
                    },
                ]);
            }
        });

        new Collection($events->pluck('visitable')->whereInstanceOf(Comment::class)->all())->loadMissing('commentable');

        $viewer = auth()->user();

        $subjectOf = fn (TrackingEvent $event): ?Model => match (true) {
            $event->visitable instanceof ModVersion => $event->visitable->mod,
            $event->visitable instanceof Comment => $event->visitable->commentable,
            default => $event->visitable,
        };

        $issueModIds = $events
            ->map($subjectOf)
            ->whereInstanceOf(ModIssue::class)
            ->pluck('mod_id')
            ->unique()
            ->all();
        $issueMods = Mod::query()->withoutGlobalScopes()->whereKey($issueModIds)->get()->keyBy('id');

        $events = $events->reject(function (TrackingEvent $event) use ($viewer, $subjectOf, $issueMods): bool {
            $subject = $subjectOf($event);

            if ($subject instanceof ModIssue) {
                $subject = $issueMods->get($subject->mod_id);
            }

            return ($subject instanceof Mod || $subject instanceof Addon || $subject instanceof ModList)
                && IgnoredContent::isHidden($subject, $viewer);
        })->values();

        // If not authenticated or not viewing own profile and not a moderator/admin, filter private events
        $authUser = auth()->user();
        if (! $authUser || ($authUser->id !== $this->user->id && ! $authUser->isModOrAdmin())) {
            return $events->reject(fn (TrackingEvent $event): bool => $this->shouldEventBePrivate($event));
        }

        return $events;
    }

    /**
     * Get the TrackingEventType enum for the given event.
     */
    public function getEventType(TrackingEvent $event): ?TrackingEventType
    {
        return $event->event_name ? TrackingEventType::tryFrom($event->event_name) : null;
    }

    /**
     * Get the icon for the given event.
     */
    public function getEventIcon(TrackingEvent $event): string
    {
        return $this->getEventType($event)?->getIcon() ?? 'document-text';
    }

    /**
     * Get the color for the given event.
     */
    public function getEventColor(TrackingEvent $event): string
    {
        return $this->getEventType($event)?->getColor() ?? 'gray';
    }

    /**
     * Determine if the event has context to show in a container.
     */
    public function hasContext(TrackingEvent $event): bool
    {
        $eventType = $this->getEventType($event);
        $shouldShowContext = ! $eventType || $eventType->shouldShowContext();

        return $shouldShowContext && ! empty($event->event_context);
    }

    /**
     * Determine if an event is private and should show the lock icon.
     */
    public function isEventPrivate(TrackingEvent $event): bool
    {
        $eventType = $this->getEventType($event);

        return $eventType && $eventType->isPrivate();
    }

    /**
     * Determine if an event should be treated as private based on event type or related models.
     * This is used to filter events for non-owners/non-admins.
     */
    private function shouldEventBePrivate(TrackingEvent $event): bool
    {
        // Check if the event type itself is private
        if (! $event->event_name) {
            return false;
        }

        $eventType = TrackingEventType::tryFrom($event->event_name);
        if ($eventType && $eventType->isPrivate()) {
            return true;
        }

        // For mod-related events, check if the mod is unpublished
        if ($event->visitable instanceof ModVersion) {
            $mod = $event->visitable->mod;
            if (is_null($mod->published_at) || $mod->published_at > now()) {
                return true;
            }
        }

        return $event->visitable instanceof Mod && (is_null($event->visitable->published_at) || $event->visitable->published_at > now());
    }
};
