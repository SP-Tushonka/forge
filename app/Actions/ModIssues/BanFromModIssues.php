<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\Mod;
use App\Models\ModIssueBan;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class BanFromModIssues
{
    /**
     * Banning someone already banned replaces the terms, so there is only ever one row per member per mod.
     */
    public function execute(Mod $mod, User $target, User $actor, ?string $reason, ?CarbonImmutable $expiresAt): ModIssueBan
    {
        $ban = ModIssueBan::query()->updateOrCreate(
            ['mod_id' => $mod->id, 'user_id' => $target->id],
            ['banned_by' => $actor->id, 'reason' => $reason, 'expires_at' => $expiresAt],
        );

        Track::event(TrackingEventType::ISSUE_BAN, $mod, ['banned_user_id' => $target->id]);

        return $ban;
    }
}
