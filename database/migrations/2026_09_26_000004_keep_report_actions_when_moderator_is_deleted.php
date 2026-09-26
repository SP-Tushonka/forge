<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A moderator deleting their account used to cascade away every moderation action they took. Keep the history and
     * show the moderator as a deleted account instead.
     */
    public function up(): void
    {
        Schema::table('report_actions', function (Blueprint $table): void {
            $table->dropForeign(['moderator_id']);
            $table->foreignId('moderator_id')->nullable()->change();
            $table->foreign('moderator_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    /**
     * Restores the cascade but leaves the column nullable, so a rollback never deletes history already kept.
     */
    public function down(): void
    {
        Schema::table('report_actions', function (Blueprint $table): void {
            $table->dropForeign(['moderator_id']);
            $table->foreign('moderator_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
