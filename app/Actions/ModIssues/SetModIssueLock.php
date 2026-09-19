<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\ModIssueEventType;
use App\Models\ModIssue;
use App\Models\User;

final readonly class SetModIssueLock
{
    public function execute(ModIssue $issue, bool $locked, User $actor): void
    {
        if ($issue->isLocked() === $locked) {
            return;
        }

        $issue->locked_at = $locked ? now() : null;
        $issue->locked_by = $locked ? $actor->id : null;
        $issue->save();

        $issue->recordEvent($locked ? ModIssueEventType::Locked : ModIssueEventType::Unlocked, $actor);
    }
}
