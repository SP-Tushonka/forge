<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->timestamp('staff_moderated_at')->nullable()->after('pinned_by');
        });

        $staffRoles = ['moderator', 'senior moderator', 'staff'];

        $moderated = DB::table('tracking_events')
            ->join('users', 'users.id', '=', 'tracking_events.visitor_id')
            ->join('user_roles', 'user_roles.id', '=', 'users.user_role_id')
            ->where('tracking_events.visitable_type', 'App\Models\Comment')
            ->where('tracking_events.is_moderation_action', true)
            ->whereIn(DB::raw('LOWER(user_roles.name)'), $staffRoles)
            ->groupBy('tracking_events.visitable_id')
            ->selectRaw('tracking_events.visitable_id as comment_id, MAX(tracking_events.created_at) as moderated_at')
            ->pluck('moderated_at', 'comment_id');

        foreach ($moderated as $commentId => $moderatedAt) {
            DB::table('comments')->where('id', $commentId)->update(['staff_moderated_at' => $moderatedAt]);
        }
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn('staff_moderated_at');
        });
    }
};
