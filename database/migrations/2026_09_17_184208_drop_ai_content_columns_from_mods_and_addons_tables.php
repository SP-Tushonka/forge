<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dropped in its own statement: the column carries a single-column index on mods, and dropping both in one
        // closure behaves inconsistently across MySQL and sqlite.
        Schema::table('mods', function (Blueprint $table): void {
            $table->dropIndex(['contains_ai_content']);
        });

        Schema::table('mods', function (Blueprint $table): void {
            $table->dropColumn([
                'contains_ai_content',
                'contains_ai_content_locked',
                'custom_ai_disclosure',
            ]);
        });

        Schema::table('addons', function (Blueprint $table): void {
            $table->dropColumn([
                'contains_ai_content',
                'contains_ai_content_locked',
                'custom_ai_disclosure',
            ]);
        });
    }

    /**
     * Restores the schema only. The disclosure text authors wrote is not recoverable.
     */
    public function down(): void
    {
        Schema::table('mods', function (Blueprint $table): void {
            $table->boolean('contains_ai_content')->default(false);
            $table->boolean('contains_ai_content_locked')->default(false);
            $table->text('custom_ai_disclosure')->nullable();
            $table->index('contains_ai_content');
        });

        Schema::table('addons', function (Blueprint $table): void {
            $table->boolean('contains_ai_content')->default(false);
            $table->boolean('contains_ai_content_locked')->default(false);
            $table->text('custom_ai_disclosure')->nullable();
        });
    }
};
