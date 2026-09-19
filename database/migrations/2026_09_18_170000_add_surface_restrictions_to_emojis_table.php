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
            // Where an emoji may be used, independently of `enabled`, which remains the master switch. All three
            // default to true so every existing row keeps behaving exactly as it did.
            $table->boolean('allow_comments')->default(true);
            $table->boolean('allow_mod_reactions')->default(true);
            $table->boolean('allow_mod_summary')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('emojis', function (Blueprint $table): void {
            $table->dropColumn(['allow_comments', 'allow_mod_reactions', 'allow_mod_summary']);
        });
    }
};
