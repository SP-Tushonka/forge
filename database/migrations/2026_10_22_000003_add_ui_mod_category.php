<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const string SLUG = 'ui';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! DB::table('mod_categories')->where('slug', self::SLUG)->exists()) {
            DB::table('mod_categories')->insert([
                'title' => 'UI',
                'slug' => self::SLUG,
                'description' => 'User interface',
                'shows_profile_binding_notice' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // The query builder fires no model events, so the memoised list would keep serving the old rows.
        Cache::forget('mod-categories:ordered');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('mod_categories')->where('slug', self::SLUG)->delete();

        Cache::forget('mod-categories:ordered');
    }
};
