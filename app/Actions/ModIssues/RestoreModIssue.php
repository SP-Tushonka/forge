<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\ModIssue;

final readonly class RestoreModIssue
{
    public function execute(ModIssue $issue): void
    {
        $issue->deleted_by = null;
        $issue->restore();

        Track::event(TrackingEventType::ISSUE_RESTORE, $issue);
    }
}
