<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Kept as a literal rather than License::CUSTOM_NAME so the migration stays valid if the model ever moves.
     */
    private const string NAME = 'Custom License';

    /**
     * Add the license modders pick when they ship their own licence text. The link column is NOT NULL with no
     * default and there is no canonical URL for a bespoke licence, so it is stored empty.
     */
    public function up(): void
    {
        if (! DB::table('licenses')->where('name', self::NAME)->exists()) {
            DB::table('licenses')->insert([
                'name' => self::NAME,
                'link' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The query builder fires no model events, so the memoised list would keep serving the old rows.
        Cache::forget('licenses:ordered');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('licenses')->where('name', self::NAME)->delete();

        Cache::forget('licenses:ordered');
    }
};
