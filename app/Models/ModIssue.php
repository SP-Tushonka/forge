<?php

declare(strict_types=1);

namespace App\Models;

use App\Contracts\Commentable;
use App\Contracts\Reactable;
use App\Contracts\Reportable;
use App\Contracts\Trackable;
use App\Contracts\VersionedCommentable;
use App\Enums\EmojiSurface;
use App\Enums\IssueNotificationLevel;
use App\Enums\ModIssueEventType;
use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Enums\VersionChange;
use App\Enums\VersionTagColor;
use App\Models\Scopes\PublishedScope;
use App\Support\Markdown\EmojiRenderContext;
use App\Traits\HasComments;
use App\Traits\HasReactions;
use App\Traits\HasReports;
use Carbon\CarbonImmutable;
use Database\Factories\ModIssueFactory;
use GrahamCampbell\Markdown\Facades\Markdown;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Override;
use Stevebauman\Purify\Facades\Purify;

/**
 * @property int $id
 * @property int $mod_id
 * @property int $number
 * @property int $user_id
 * @property ModIssueType $type
 * @property ModIssueStatus $status
 * @property string $title
 * @property string $body
 * @property int|null $affected_mod_version_id
 * @property string|null $fixed_version
 * @property CarbonImmutable|null $fix_notified_at
 * @property int|null $duplicate_of_id
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property CarbonImmutable|null $locked_at
 * @property int|null $locked_by
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable $last_activity_at
 * @property CarbonImmutable|null $deleted_at
 * @property int|null $deleted_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read string $body_html
 * @property-read Mod $mod
 * @property-read User $user
 * @property-read ModVersion|null $affectedVersion
 * @property-read ModIssue|null $duplicateOf
 * @property-read Collection<int, ModIssueEvent> $events
 *
 * @implements Commentable<self>
 */
final class ModIssue extends Model implements Commentable, Reactable, Reportable, Trackable, VersionedCommentable
{
    /** @use HasComments<self> */
    use HasComments;

    /** @use HasFactory<ModIssueFactory> */
    use HasFactory;

    /** @use HasReactions<self> */
    use HasReactions;

    /** @use HasReports<ModIssue> */
    use HasReports;

    use SoftDeletes;

    /**
     * Visibility is the policy's job, so the issue always resolves its mod, published or not.
     *
     * @return BelongsTo<Mod, $this>
     */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class)->withoutGlobalScope(PublishedScope::class);
    }

    /**
     * The reporter.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ModVersion, $this>
     */
    public function affectedVersion(): BelongsTo
    {
        return $this->belongsTo(ModVersion::class, 'affected_mod_version_id')->withoutGlobalScope(PublishedScope::class);
    }

    /**
     * Resolves to null once the original is deleted, which is what hides the duplicate banner's link.
     *
     * @return BelongsTo<ModIssue, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    /**
     * @return HasMany<ModIssueEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ModIssueEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return MorphMany<NotificationMute, $this>
     */
    public function mutes(): MorphMany
    {
        return $this->morphMany(NotificationMute::class, 'mutable');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * Managers (owner and co-authors) and staff.
     */
    public function canBeManagedBy(User $user): bool
    {
        return $user->isModOrAdmin() || $this->mod->isAuthorOrOwner($user);
    }

    public function url(): string
    {
        return route('mod.issue.show', ['modId' => $this->mod_id, 'slug' => $this->mod->slug, 'number' => $this->number]);
    }

    public function recordEvent(ModIssueEventType $type, ?User $actor, ?string $from = null, ?string $to = null): ModIssueEvent
    {
        return $this->events()->create([
            'user_id' => $actor?->id,
            'type' => $type,
            'from' => $from,
            'to' => $to,
        ]);
    }

    /**
     * Drop anyone who switched issue notifications off, or muted this issue or its mod.
     *
     * @param  SupportCollection<int, User>  $users
     * @return SupportCollection<int, User>
     */
    public function withoutOptedOut(SupportCollection $users): SupportCollection
    {
        if ($users->isEmpty()) {
            return $users;
        }

        $mutedIds = $this->mutesQuery()
            ->whereIn('user_id', $users->pluck('id'))
            ->get(['user_id'])
            ->map(fn (NotificationMute $mute): int => $mute->user_id)
            ->all();

        return $users
            ->reject(fn (User $user): bool => $user->issueNotificationLevel() === IssueNotificationLevel::Off
                || in_array($user->id, $mutedIds, true))
            ->values();
    }

    public function allowsNotificationTo(User $user): bool
    {
        return $this->withoutOptedOut(collect([$user]))->isNotEmpty();
    }

    /**
     * Automatic subscriptions (opening, managing, commenting) respect mutes. Unlike subscribeUser(), they never undo
     * a choice the user made.
     */
    public function subscribeUnlessMuted(User $user): void
    {
        if ($this->mutesQuery()->where('user_id', $user->id)->exists()) {
            return;
        }

        CommentSubscription::subscribe($user, $this);
    }

    /**
     * An explicit subscribe clears an earlier mute of this issue, so the choice just made wins.
     */
    public function subscribeUser(User $user): CommentSubscription
    {
        $user->unmute($this);

        return CommentSubscription::subscribe($user, $this);
    }

    /**
     * Unsubscribing also mutes, so taking part in the thread later does not quietly subscribe them again.
     */
    public function unsubscribeUser(User $user): bool
    {
        $user->mute($this);

        return CommentSubscription::unsubscribe($user, $this);
    }

    /**
     * @return SupportCollection<int, User>
     */
    public function getSubscribers(): SupportCollection
    {
        /** @var SupportCollection<int, User> $subscribers */
        $subscribers = $this->commentSubscriptions()->with('user')->get()->pluck('user')->filter()->values();

        return $this->withoutOptedOut($subscribers);
    }

    public function canReceiveComments(): bool
    {
        if ($this->isLocked() || $this->trashed()) {
            return false;
        }

        return $this->mod->issues_enabled && ! $this->mod->disabled && $this->mod->isPublished();
    }

    public function getCommentableDisplayName(): string
    {
        return 'issue';
    }

    public function getTitle(): string
    {
        return sprintf('#%d %s', $this->number, $this->title);
    }

    public function getCommentableUrl(): string
    {
        return $this->url();
    }

    /**
     * Comments render inline under the issue; this gives the same plain anchors mod lists use.
     */
    public function getCommentTabHash(): string
    {
        return 'comments';
    }

    public function getCommentableVersion(): ?string
    {
        return $this->mod->getCommentableVersion();
    }

    public function getCommentVersionTagColor(VersionChange $change): VersionTagColor
    {
        return $this->mod->getCommentVersionTagColor($change);
    }

    public function canReceiveReactions(): bool
    {
        return ! $this->trashed() && ! $this->mod->disabled && $this->mod->isPublished();
    }

    public function getReportableDisplayName(): string
    {
        return 'issue';
    }

    public function getReportableTitle(): string
    {
        return $this->getTitle();
    }

    public function getReportableExcerpt(): string
    {
        return Str::words($this->body, 15, '...');
    }

    public function getReportableUrl(): string
    {
        return $this->url();
    }

    public function getTrackingUrl(): string
    {
        return $this->url();
    }

    public function getTrackingTitle(): string
    {
        return $this->getTitle();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrackingSnapshot(): array
    {
        return [
            'issue_title' => $this->title,
            'issue_number' => $this->number,
            'mod_name' => $this->mod->name,
        ];
    }

    public function getTrackingContext(): string
    {
        return $this->body;
    }

    #[Override]
    protected static function booted(): void
    {
        // Numbers come from the mod's counter rather than MAX(number), so a deleted issue's number is never reused.
        // Query builder, so the mod's updated_at (which drives Recently Updated) stays put.
        self::creating(function (ModIssue $issue): void {
            if ($issue->getAttribute('number') !== null) {
                return;
            }

            DB::table('mods')->where('id', $issue->mod_id)->increment('last_issue_number');
            $issue->number = Mod::query()
                ->withoutGlobalScope(PublishedScope::class)
                ->select(['id', 'last_issue_number'])
                ->findOrFail($issue->mod_id)
                ->last_issue_number;
        });

        // Comments, reactions, reports, subscriptions and mutes point here polymorphically, so no foreign key removes
        // them. Tracking events are kept as the audit log, as they are for deleted comments.
        self::forceDeleting(function (ModIssue $issue): void {
            $commentIds = $issue->comments()->pluck('id');

            Reaction::query()->where('reactable_type', Comment::class)->whereIn('reactable_id', $commentIds)->delete();
            Report::query()->where('reportable_type', Comment::class)->whereIn('reportable_id', $commentIds)->delete();

            $issue->comments()->delete();
            $issue->reactions()->delete();
            $issue->reports()->delete();
            $issue->commentSubscriptions()->delete();
            $issue->mutes()->delete();
        });
    }

    /**
     * @param  Builder<ModIssue>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (ModIssueStatus $status): string => $status->value, ModIssueStatus::open()));
    }

    /**
     * @param  Builder<ModIssue>  $query
     */
    #[Scope]
    protected function closed(Builder $query): void
    {
        $query->whereIn('status', array_map(fn (ModIssueStatus $status): string => $status->value, ModIssueStatus::closed()));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function bodyHtml(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                /** @var string $clean */
                $clean = Purify::config('comments')->clean(
                    EmojiRenderContext::scoped(
                        EmojiSurface::Comments,
                        fn (): string => Markdown::convert($this->body)->getContent(),
                    )
                );

                return $clean;
            }
        )->shouldCache();
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'mod_id' => 'integer',
            'number' => 'integer',
            'user_id' => 'integer',
            'type' => ModIssueType::class,
            'status' => ModIssueStatus::class,
            'affected_mod_version_id' => 'integer',
            'fix_notified_at' => 'datetime',
            'duplicate_of_id' => 'integer',
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
            'locked_at' => 'datetime',
            'locked_by' => 'integer',
            'edited_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'deleted_at' => 'datetime',
            'deleted_by' => 'integer',
        ];
    }

    /**
     * Mutes that silence this issue: on the issue itself, or on its whole mod.
     *
     * @return Builder<NotificationMute>
     */
    private function mutesQuery(): Builder
    {
        return NotificationMute::query()->where(function (Builder $query): void {
            $query->where(fn (Builder $issue): Builder => $issue->where('mutable_type', self::class)->where('mutable_id', $this->id))
                ->orWhere(fn (Builder $mod): Builder => $mod->where('mutable_type', Mod::class)->where('mutable_id', $this->mod_id));
        });
    }
}
