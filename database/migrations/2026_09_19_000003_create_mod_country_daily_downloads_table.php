<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (mod, UTC day, country) of downloads. Downloads without a resolvable country are stored as `XX`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mod_country_daily_downloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mod_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->char('country_code', 2);
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamps();

            $table->unique(['mod_id', 'date', 'country_code'], 'mod_country_daily_downloads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mod_country_daily_downloads');
    }
};
