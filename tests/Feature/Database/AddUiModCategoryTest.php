<?php

declare(strict_types=1);

use App\Models\ModCategory;
use Illuminate\Support\Facades\DB;

describe('Add UI mod category migration', function (): void {
    it('is inserted once, shows in the cached list straight away, and is removed on rollback', function (): void {
        $migration = require database_path('migrations/2026_10_22_000003_add_ui_mod_category.php');
        $migration->down();

        ModCategory::cachedOrdered();

        $migration->up();
        $migration->up();

        expect(DB::table('mod_categories')->where('slug', 'ui')->count())->toBe(1)
            ->and(ModCategory::cachedOrdered()->firstWhere('slug', 'ui'))
            ->title->toBe('UI')
            ->description->toBe('User interface')
            ->shows_profile_binding_notice->toBeFalse();

        $migration->down();

        expect(DB::table('mod_categories')->where('slug', 'ui')->exists())->toBeFalse()
            ->and(ModCategory::cachedOrdered()->firstWhere('slug', 'ui'))->toBeNull();
    });
});
