<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\Reaction;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush(); // Prevent rate limiting interference.

    SptVersion::factory()->create(['version' => '1.0.0']);
});

describe('Mod reaction bar', function (): void {
    it('adds a reaction from the mod detail page and removes it again', function (): void {
        $author = User::factory()->create();
        $reactor = User::factory()->create();

        $mod = Mod::factory()->recycle($author)->create([
            'disabled' => false,
            'published_at' => now()->subHour(),
        ]);
        ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

        $this->actingAs($reactor);

        $page = visit($mod->detail_url)
            ->on()->desktop()
            ->waitForText($mod->name);

        // Nothing reacted yet, so only the picker shows.
        $page->assertNotPresent('@reaction-chip-'.$mod->id.'-heart')
            ->click('@reaction-add-'.$mod->id)
            ->click('@reaction-pick-'.$mod->id.'-heart')
            ->assertPresent('@reaction-chip-'.$mod->id.'-heart')
            ->assertNoJavaScriptErrors();

        expect(Reaction::query()->where('reactable_type', Mod::class)->where('reactable_id', $mod->id)->count())
            ->toBe(1);

        $page->click('@reaction-chip-'.$mod->id.'-heart')
            ->assertNotPresent('@reaction-chip-'.$mod->id.'-heart')
            ->assertNoJavaScriptErrors();

        expect(Reaction::query()->count())->toBe(0);
    });

    it('replaces the existing reaction when a different emoji is picked', function (): void {
        $reactor = User::factory()->create();

        $mod = Mod::factory()->create([
            'disabled' => false,
            'published_at' => now()->subHour(),
        ]);
        ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

        $this->actingAs($reactor);

        $page = visit($mod->detail_url)
            ->on()->desktop()
            ->waitForText($mod->name);

        $page->click('@reaction-add-'.$mod->id)
            ->click('@reaction-pick-'.$mod->id.'-heart')
            ->assertPresent('@reaction-chip-'.$mod->id.'-heart')
            ->click('@reaction-add-'.$mod->id)
            ->click('@reaction-pick-'.$mod->id.'-fire')
            ->assertPresent('@reaction-chip-'.$mod->id.'-fire')
            ->assertNotPresent('@reaction-chip-'.$mod->id.'-heart')
            ->assertNoJavaScriptErrors();

        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $reaction = Reaction::query()->sole();

        expect($reaction->emoji_id)->toBe($fire->id);
    });

    it('sizes the picker to its contents so no emoji wraps onto a row of its own', function (): void {
        $viewer = User::factory()->create();

        $mod = Mod::factory()->create([
            'disabled' => false,
            'published_at' => now()->subHour(),
        ]);
        ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

        // Two beyond the five starters: the fixed-width panel fitted exactly six, so the seventh wrapped and left
        // dead space on the first row.
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

        $page = visit($mod->detail_url)
            ->on()->desktop()
            ->waitForText($mod->name)
            ->click('@reaction-add-'.$mod->id);

        // The panel must genuinely be on screen first: a hidden element reports offsetTop 0 for every child, which
        // would make the single-row check below pass no matter how badly it wrapped.
        $page->assertVisible('@reaction-picker-'.$mod->id);

        // Every emoji button sharing one offsetTop means a single row: the panel grew to fit rather than wrapping.
        $script = <<<JS
            (() => {
                const panel = document.querySelector('[data-test="reaction-picker-{$mod->id}"]');
                if (! panel) { return 'panel missing'; }
                if (panel.offsetHeight === 0) { return 'panel not rendered'; }

                const buttons = Array.from(panel.querySelectorAll('button'));
                if (buttons.length !== 7) { return 'expected 7 emoji, got ' + buttons.length; }
                if (buttons.some(b => b.offsetWidth === 0)) { return 'emoji have no width'; }

                const rows = new Set(buttons.map(b => b.offsetTop));

                return rows.size === 1 ? true : 'wrapped onto ' + rows.size + ' rows';
            })()
            JS;

        $page->assertScript($script, true)->assertNoJavaScriptErrors();
    });

    it('shows a read-only summary on a browse card, with no way to react from there', function (): void {
        $viewer = User::factory()->create();

        $mod = Mod::factory()->create([
            'disabled' => false,
            'published_at' => now()->subHour(),
        ]);
        ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

        $mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => Emoji::query()->where('shortcode', 'fire')->sole()->id,
        ]);

        $this->actingAs($viewer);

        visit(route('mods'))
            ->on()->desktop()
            ->waitForText($mod->name)
            ->assertPresent('@reaction-summary-'.$mod->id)
            ->assertNotPresent('@reaction-add-'.$mod->id)
            ->assertNoJavaScriptErrors();

        expect(Reaction::query()->count())->toBe(1);
    });

    it('reveals the per-emoji breakdown when the card summary is hovered', function (): void {
        $viewer = User::factory()->create();

        $mod = Mod::factory()->create([
            'disabled' => false,
            'published_at' => now()->subHour(),
        ]);
        ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

        foreach (['fire', 'fire', 'heart'] as $shortcode) {
            $mod->reactions()->create([
                'user_id' => User::factory()->create()->id,
                'emoji_id' => Emoji::query()->where('shortcode', $shortcode)->sole()->id,
            ]);
        }

        $this->actingAs($viewer);

        $page = visit(route('mods'))
            ->on()->desktop()
            ->waitForText($mod->name);

        // x-show keeps the panel in the DOM but hidden, so presence proves nothing - assertMissing is the
        // visibility check here, and it has to flip to visible on hover.
        $page->assertPresent('@reaction-breakdown-'.$mod->id)
            ->assertMissing('@reaction-breakdown-'.$mod->id)
            ->hover('@reaction-summary-'.$mod->id)
            ->assertVisible('@reaction-breakdown-'.$mod->id)
            ->assertSeeIn('[data-test="reaction-breakdown-'.$mod->id.'"]', 'Fire')
            ->assertSeeIn('[data-test="reaction-breakdown-'.$mod->id.'"]', 'Heart')
            ->assertNoJavaScriptErrors();
    });
});
