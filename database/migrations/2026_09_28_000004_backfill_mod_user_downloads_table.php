<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds each user's latest download of each mod from the download events still inside the tracking retention window.
 * Kept apart from the table's creation so a failed backfill can be re-run; existing rows are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ranked = DB::table('tracking_events')
            ->join('mod_versions', 'mod_versions.id', '=', 'tracking_events.visitable_id')
            ->join('users', 'users.id', '=', 'tracking_events.visitor_id')
            ->where('tracking_events.event_name', 'mod_download')
            ->where('tracking_events.visitable_type', 'App\\Models\\ModVersion')
            ->where('tracking_events.visitor_type', 'App\\Models\\User')
            ->whereNotNull('tracking_events.created_at')
            ->select([
                'tracking_events.visitor_id as user_id',
                'mod_versions.mod_id',
                'mod_versions.id as mod_version_id',
                'mod_versions.version',
                'tracking_events.created_at as downloaded_at',
            ])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY tracking_events.visitor_id, mod_versions.mod_id ORDER BY tracking_events.created_at DESC, tracking_events.id DESC) AS position');

        DB::table('mod_user_downloads')->insertOrIgnoreUsing(
            ['user_id', 'mod_id', 'mod_version_id', 'version', 'downloaded_at', 'created_at', 'updated_at'],
            DB::query()
                ->fromSub($ranked, 'ranked')
                ->where('position', 1)
                ->select(['user_id', 'mod_id', 'mod_version_id', 'version', 'downloaded_at', 'downloaded_at as created_at', 'downloaded_at as updated_at']),
        );
    }

    public function down(): void
    {
        DB::table('mod_user_downloads')->delete();
    }
};
