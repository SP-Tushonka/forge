<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The profile page ban button logged bans and unbans without the moderation flag, which hid them from the moderation
 * log. The moderator's reason only reached the ban row, so it is recovered from there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $events = DB::table('tracking_events')
            ->whereIn('event_name', ['user_ban', 'user_unban'])
            ->where('is_moderation_action', false)
            ->get(['id', 'event_name', 'visitor_id', 'visitable_id', 'created_at']);

        foreach ($events as $event) {
            $loggedAt = CarbonImmutable::parse($event->created_at);

            $reason = $event->event_name === 'user_ban'
                ? DB::table('bans')
                    ->where('bannable_type', User::class)
                    ->where('bannable_id', $event->visitable_id)
                    ->where('created_by_id', $event->visitor_id)
                    ->whereBetween('created_at', [$loggedAt->subMinute(), $loggedAt->addMinute()])
                    ->value('comment')
                : null;

            DB::table('tracking_events')->where('id', $event->id)->update(['is_moderation_action' => true, 'reason' => $reason]);
        }
    }

    public function down(): void
    {
        // Deliberately empty: unflagging these rows would hide real moderation actions from the log again.
    }
};
