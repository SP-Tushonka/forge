<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (mod version, UTC day) of downloads, for the stats page's per-version adoption chart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_version_daily_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mod_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->unique(['mod_version_id', 'date'], 'mod_version_daily_downloads_version_date_unique');
            $table->index(['mod_id', 'date'], 'mod_version_daily_downloads_mod_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_version_daily_downloads');
    }
};
