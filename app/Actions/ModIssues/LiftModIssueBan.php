<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\ModIssueBan;

final readonly class LiftModIssueBan
{
    public function execute(ModIssueBan $ban): void
    {
        $mod = $ban->mod;
        $bannedUserId = $ban->user_id;

        $ban->delete();

        Track::event(TrackingEventType::ISSUE_UNBAN, $mod, ['banned_user_id' => $bannedUserId]);
    }
}
