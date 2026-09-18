<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The starter whitelist. These rows live in the migration rather than a seeder because production needs them and
     * because the heart row is the target the reactions backfill maps every legacy like onto.
     *
     * Codepoints are stored rather than derived: Twemoji strips U+FE0F from most sequences, so ❤️ (U+2764 U+FE0F) is
     * served as 2764.svg and a filename derived from the glyph would 404.
     */
    private const array STARTERS = [
        ['shortcode' => 'heart', 'glyph' => '❤️', 'codepoints' => '2764', 'label' => 'Heart', 'sort_order' => 1],
        ['shortcode' => 'thumbsup', 'glyph' => '👍', 'codepoints' => '1f44d', 'label' => 'Thumbs up', 'sort_order' => 2],
        ['shortcode' => 'joy', 'glyph' => '😂', 'codepoints' => '1f602', 'label' => 'Laughing', 'sort_order' => 3],
        ['shortcode' => 'fire', 'glyph' => '🔥', 'codepoints' => '1f525', 'label' => 'Fire', 'sort_order' => 4],
        ['shortcode' => 'tada', 'glyph' => '🎉', 'codepoints' => '1f389', 'label' => 'Celebrate', 'sort_order' => 5],
    ];

    public function up(): void
    {
        Schema::create('emojis', function (Blueprint $table): void {
            $table->id();
            $table->string('shortcode')->unique();
            $table->string('glyph');
            // Unique: an emoji may appear once whatever staff name it. Keying only on shortcode let the same glyph be
            // added twice under two names.
            $table->string('codepoints')->unique();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });

        $now = now();

        foreach (self::STARTERS as $starter) {
            DB::table('emojis')->updateOrInsert(
                ['shortcode' => $starter['shortcode']],
                [...$starter, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('emojis');
    }
};
