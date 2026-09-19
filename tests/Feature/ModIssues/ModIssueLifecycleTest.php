<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Models\Comment;
use App\Models\CommentSubscription;
use App\Models\ModIssue;
use App\Models\NotificationMute;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->mod = modWithIssues();
    $this->issue = ModIssue::factory()->for($this->mod)->create(['last_activity_at' => now()->subWeek()]);
});

function answerIssue(ModIssue $issue, User $author): Comment
{
    return Comment::factory()->create([
        'commentable_type' => ModIssue::class,
        'commentable_id' => $issue->id,
        'user_id' => $author->id,
    ]);
}

describe('comments on issues', function (): void {
    it('hands a Needs info issue back when the reporter answers', function (): void {
        $this->issue->update(['status' => ModIssueStatus::NeedsInfo]);

        answerIssue($this->issue, $this->issue->user);

        $fresh = $this->issue->fresh();

        expect($fresh?->status)->toBe(ModIssueStatus::New)
            ->and($fresh?->events()->sole()->user_id)->toBeNull();
    });

    it('leaves Needs info alone when someone else answers', function (): void {
        $this->issue->update(['status' => ModIssueStatus::NeedsInfo]);

        answerIssue($this->issue, User::factory()->create());

        expect($this->issue->fresh()?->status)->toBe(ModIssueStatus::NeedsInfo);
    });

    it('subscribes commenters unless they muted the issue, and bumps activity', function (): void {
        $commenter = User::factory()->create();
        $muted = User::factory()->create();
        $muted->mute($this->issue);

        answerIssue($this->issue, $commenter);
        answerIssue($this->issue, $muted);

        expect($this->issue->isUserSubscribed($commenter))->toBeTrue()
            ->and($this->issue->isUserSubscribed($muted))->toBeFalse()
            ->and($this->issue->fresh()?->last_activity_at->isAfter(now()->subDay()))->toBeTrue();
    });
});

describe('deletion', function (): void {
    it('takes comments, reactions, subscriptions and mutes with a force-deleted issue', function (): void {
        $comment = answerIssue($this->issue, User::factory()->create());
        Reaction::factory()->for($comment, 'reactable')->create();
        Reaction::factory()->for($this->issue, 'reactable')->create();
        User::factory()->create()->mute($this->issue);

        $this->issue->forceDelete();

        expect(Comment::query()->whereKey($comment->id)->exists())->toBeFalse()
            ->and(Reaction::query()->count())->toBe(0)
            ->and(CommentSubscription::query()->where('commentable_type', ModIssue::class)->count())->toBe(0)
            ->and(NotificationMute::query()->count())->toBe(0);
    });

    it('removes the issues of a deleted account, with the discussion on them', function (): void {
        $comment = answerIssue($this->issue, User::factory()->create());

        $this->issue->user->delete();

        expect(ModIssue::withTrashed()->whereKey($this->issue->id)->exists())->toBeFalse()
            ->and(Comment::query()->whereKey($comment->id)->exists())->toBeFalse();
    });

    it('removes the issues of a deleted mod, with the discussion on them', function (): void {
        $comment = answerIssue($this->issue, User::factory()->create());

        $this->mod->delete();

        expect(ModIssue::withTrashed()->whereKey($this->issue->id)->exists())->toBeFalse()
            ->and(Comment::query()->whereKey($comment->id)->exists())->toBeFalse();
    });
});
