<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emojis', function (Blueprint $table): void {
            // The fourth surface is a mod's description text, not the summarised figure on mod cards. The card total
            // is a rollup of mod reactions and follows that permission instead, so it needs no column of its own.
            $table->renameColumn('allow_mod_summary', 'allow_mod_description');
        });
    }

    public function down(): void
    {
        Schema::table('emojis', function (Blueprint $table): void {
            $table->renameColumn('allow_mod_description', 'allow_mod_summary');
        });
    }
};
