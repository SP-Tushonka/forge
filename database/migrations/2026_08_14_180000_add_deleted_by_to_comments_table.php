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
            $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
        });

        $actors = DB::table('tracking_events')
            ->join('users', 'users.id', '=', 'tracking_events.visitor_id')
            ->where('tracking_events.visitable_type', 'App\Models\Comment')
            ->where('tracking_events.event_name', 'comment_soft_delete')
            ->orderBy('tracking_events.created_at')
            ->pluck('tracking_events.visitor_id', 'tracking_events.visitable_id');

        foreach ($actors as $commentId => $userId) {
            DB::table('comments')
                ->where('id', $commentId)
                ->whereNotNull('deleted_at')
                ->update(['deleted_by' => $userId]);
        }
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
