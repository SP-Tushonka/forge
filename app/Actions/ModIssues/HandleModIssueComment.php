<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\ModIssueStatus;
use App\Models\Comment;
use App\Models\ModIssue;

final readonly class HandleModIssueComment
{
    public function __construct(
        private ChangeModIssueStatus $changeStatus,
    ) {}

    /**
     * Follows the thread for the commenter and bumps the issue's activity. A Needs info issue goes back to the
     * managers once the reporter answers.
     */
    public function execute(Comment $comment): void
    {
        $issue = ModIssue::query()->find($comment->commentable_id);

        if (! $issue instanceof ModIssue) {
            return;
        }

        $issue->subscribeUnlessMuted($comment->user);

        $issue->last_activity_at = now();
        $issue->save();

        if ($issue->status === ModIssueStatus::NeedsInfo && $comment->user_id === $issue->user_id) {
            $this->changeStatus->execute($issue, ModIssueStatus::New, null);
        }
    }
}
