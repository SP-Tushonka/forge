<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Throwaway: captures the reaction bar in both variants so the styling can be eyeballed. Not a behavioural test -
 * delete once the look is signed off.
 */
beforeEach(function (): void {
    Cache::flush();
    SptVersion::factory()->create(['version' => '1.0.0']);
});

it('captures the open picker panel at several whitelist sizes', function (): void {
    $viewer = User::factory()->create();
    $mod = Mod::factory()->create([
        'name' => 'Reaction Bar Preview',
        'disabled' => false,
        'published_at' => now()->subHour(),
    ]);
    ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

    // Seven enabled emoji, matching the whitelist that exposed the fixed-width bug: six fitted, the seventh wrapped.
    foreach ([['rocket', '1f680'], ['100', '1f4af']] as [$shortcode, $codepoints]) {
        Emoji::query()->create([
            'shortcode' => $shortcode,
            'glyph' => '*',
            'codepoints' => $codepoints,
            'label' => ucfirst($shortcode),
            'sort_order' => 90,
            'enabled' => true,
        ]);
    }

    $this->actingAs($viewer);

    visit($mod->detail_url)
        ->on()->desktop()
        ->waitForText($mod->name)
        ->click('@reaction-add-'.$mod->id)
        ->screenshot(false, 'reaction-picker-open');
})->skip(! env('CAPTURE_REACTION_BAR', false), 'Set CAPTURE_REACTION_BAR=1 to regenerate the preview images.');

it('captures the card summary and its hover breakdown', function (): void {
    $viewer = User::factory()->create();
    $mod = Mod::factory()->create([
        'name' => 'Reaction Bar Preview Card',
        'disabled' => false,
        'published_at' => now()->subHour(),
    ]);
    ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

    // Fire leads, so it should be the representative glyph; the total should read 6.
    foreach (['fire', 'fire', 'fire', 'heart', 'heart', 'tada'] as $shortcode) {
        $mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => Emoji::query()->where('shortcode', $shortcode)->sole()->id,
        ]);
    }

    $this->actingAs($viewer);

    $page = visit(route('mods'))
        ->on()->desktop()
        ->waitForText($mod->name);

    $page->screenshotElement('[data-test="reaction-summary-'.$mod->id.'"]', 'reaction-card-summary')
        ->hover('@reaction-summary-'.$mod->id)
        ->screenshot(false, 'reaction-card-hover');
})->skip(! env('CAPTURE_REACTION_BAR', false), 'Set CAPTURE_REACTION_BAR=1 to regenerate the preview images.');

it('captures the staff tools reaction pane', function (): void {
    $staff = User::factory()->admin()->create();

    // A long shortcode beside short ones is what exposed the ragged alignment.
    foreach ([['1st_place_medal', '1f947'], ['horse', '1f434']] as [$shortcode, $codepoints]) {
        Emoji::query()->create([
            'shortcode' => $shortcode,
            'glyph' => '*',
            'codepoints' => $codepoints,
            'label' => ucfirst(str_replace('_', ' ', $shortcode)),
            'sort_order' => 90,
            'enabled' => true,
        ]);
    }

    $this->actingAs($staff);

    visit(route('admin.staff-tools').'#reactions')
        ->on()->desktop()
        ->waitForText('Reaction emoji')
        ->screenshot(false, 'staff-reaction-pane');
})->skip(! env('CAPTURE_REACTION_BAR', false), 'Set CAPTURE_REACTION_BAR=1 to regenerate the preview images.');
