<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    $this->staff = User::factory()->admin()->create();
});

describe('Staff emoji catalogue', function (): void {
    it('renders the whole catalogue rather than a capped page', function (): void {
        $this->actingAs($this->staff);

        $page = visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Add an emoji');

        // Previously capped at 120. The count beside the search box is the rendered total.
        $page->assertScript(
            'document.querySelectorAll(\'[data-test="emoji-catalogue"] button\').length > 1000',
            true,
        )->assertNoJavaScriptErrors();
    });

    it('filters in the browser with no server round trip', function (): void {
        $this->actingAs($this->staff);

        $page = visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Add an emoji');

        $page->type('@emoji-search', 'rocket')
            ->assertPresent('@emoji-add-rocket')
            ->assertScript(
                'document.querySelectorAll(\'[data-test="emoji-catalogue"] button\').length < 20',
                true,
            )
            ->assertNoJavaScriptErrors();
    });

    it('adds the emoji that is clicked', function (): void {
        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Add an emoji')
            ->type('@emoji-search', 'rocket')
            ->click('@emoji-add-rocket')
            // The new row, not the toast: a toast is transient and has proven able to go missing entirely under CI
            // load, while the row it announces is what staff are actually left with. The heading itself is asserted
            // in tests/Feature/Livewire/Admin/StaffToolsEmojiToolTest.php, where dispatch is deterministic.
            ->assertPresent('@emoji-delete-rocket')
            ->assertNoJavaScriptErrors();

        $added = Emoji::query()->where('shortcode', 'rocket')->sole();

        expect($added->codepoints)->toBe('1f680');
    });

    it('fetches only the artwork in view, and keeps it across a round trip', function (): void {
        // loading="lazy" still fetched every SVG in the scroller, and decoding ~1,500 of them stalled the page long
        // enough under CI load for Livewire responses to land after the assertion window.
        $thumbsup = Emoji::query()->where('shortcode', 'thumbsup')->sole();

        $this->actingAs($this->staff);

        $someArtworkFetched = <<<'JS'
            (() => {
                const images = document.querySelectorAll('[data-test="emoji-catalogue"] img');
                const fetched = [...images].filter((image) => image.getAttribute('src')).length;

                return fetched > 0 && fetched < images.length / 2;
            })()
            JS;

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Add an emoji')
            ->assertScript($someArtworkFetched, true)
            ->type('@emoji-shortcode-'.$thumbsup->id, '+1')
            ->click('@emoji-save-thumbsup')
            ->assertNotPresent('@emoji-save-thumbsup')
            ->assertScript($someArtworkFetched, true)
            ->assertNoJavaScriptErrors();
    });

    it('lays the grid out evenly, with every cell the same size', function (): void {
        $this->actingAs($this->staff);

        $page = visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Add an emoji');

        // Uneven cells were what made the grid look ragged when each button carried its own tooltip wrapper.
        $script = <<<'JS'
            (() => {
                const buttons = Array.from(
                    document.querySelectorAll('[data-test="emoji-catalogue"] button'),
                ).slice(0, 40);

                if (buttons.length < 40) { return 'only ' + buttons.length + ' buttons'; }

                const widths = new Set(buttons.map((b) => Math.round(b.getBoundingClientRect().width)));

                return widths.size === 1 ? true : 'cell widths vary: ' + [...widths].join(', ');
            })()
            JS;

        $page->assertScript($script, true)->assertNoJavaScriptErrors();
    });
});
