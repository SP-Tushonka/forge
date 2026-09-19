<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (mod, UTC day) for the mod stats dashboard. `downloads` is rolled up from tracking_events and `views` from
 * Cloudflare; each sync step writes only its own column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_daily_stats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('downloads')->default(0);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            // Drives the overwrite upserts and serves per-mod range reads through its leading column.
            $table->unique(['mod_id', 'date'], 'mod_daily_stats_mod_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_daily_stats');
    }
};
