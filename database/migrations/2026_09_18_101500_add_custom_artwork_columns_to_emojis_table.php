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
            // Set together, and only for a staff-uploaded emoji: the normalised WebP and a SHA-256 of its bytes.
            // The hash is unique so the same artwork cannot be added twice under two shortcodes, which is the rule
            // codepoints already enforces for the Twemoji rows.
            $table->string('image_path')->nullable()->after('label');
            $table->string('image_hash', 64)->nullable()->unique()->after('image_path');
        });

        Schema::table('emojis', function (Blueprint $table): void {
            // A custom emoji has no Unicode identity at all. Repeated NULLs are permitted in a unique index by every
            // database this runs on, so codepoints keeps identifying the Twemoji rows exactly as before.
            $table->string('glyph')->nullable()->change();
            $table->string('codepoints')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('emojis', function (Blueprint $table): void {
            $table->dropUnique(['image_hash']);
            $table->dropColumn(['image_path', 'image_hash']);
        });

        // glyph and codepoints are deliberately left nullable. Any custom emoji added while this migration was
        // applied has neither, so restoring NOT NULL would fail on exactly the rows a rollback is meant to survive.
    }
};
