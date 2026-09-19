<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\ModIssue;
use App\Models\User;

final readonly class DeleteModIssue
{
    public function execute(ModIssue $issue, User $actor): void
    {
        $issue->deleted_by = $actor->id;
        $issue->save();
        $issue->delete();

        Track::event(TrackingEventType::ISSUE_DELETE, $issue);
    }
}
