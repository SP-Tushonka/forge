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
            ->waitForText('Saved')
            ->assertNoJavaScriptErrors();

        $added = Emoji::query()->where('shortcode', 'rocket')->sole();

        expect($added->codepoints)->toBe('1f680');
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
