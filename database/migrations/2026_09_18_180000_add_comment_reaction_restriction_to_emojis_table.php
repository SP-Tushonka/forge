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
            // Reacting to a comment is separate from writing :shortcode: in one, so allow_comments governs the text
            // and this governs the bar beneath it. Defaults to true, like the other three.
            $table->boolean('allow_comment_reactions')->default(true)->after('allow_comments');
        });
    }

    public function down(): void
    {
        Schema::table('emojis', function (Blueprint $table): void {
            $table->dropColumn('allow_comment_reactions');
        });
    }
};
