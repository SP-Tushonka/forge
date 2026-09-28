<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff watches on a user's indicators, the values each watch looks for, the accounts that matched, and how far
     * the sweep has read each source table.
     */
    public function up(): void
    {
        Schema::create('alt_watches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('watched_user_name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->string('match_mode', 8);
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('ended_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ended_at', 'expires_at']);
        });

        Schema::create('alt_watch_indicators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alt_watch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->text('value');
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('alt_watch_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alt_watch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('matched_indicator_ids');
            $table->boolean('baseline')->default(false);
            $table->timestamp('first_matched_at');
            $table->timestamp('last_matched_at');
            $table->string('review_outcome', 16)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['alt_watch_id', 'user_id']);
            $table->index(['baseline', 'review_outcome']);
        });

        Schema::create('alt_monitor_cursors', function (Blueprint $table): void {
            $table->string('source', 32)->primary();
            $table->string('position', 32);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alt_monitor_cursors');
        Schema::dropIfExists('alt_watch_matches');
        Schema::dropIfExists('alt_watch_indicators');
        Schema::dropIfExists('alt_watches');
    }
};
