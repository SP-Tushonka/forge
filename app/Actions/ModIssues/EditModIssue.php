<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\ModIssue;

final readonly class EditModIssue
{
    public function execute(ModIssue $issue, string $title, string $body): void
    {
        $issue->title = $title;
        $issue->body = $body;

        if (! $issue->isDirty()) {
            return;
        }

        $issue->edited_at = now();
        $issue->save();

        Track::event(TrackingEventType::ISSUE_EDIT, $issue);
    }
}
