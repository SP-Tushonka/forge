<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
});

describe('Staff emoji tool', function (): void {
    it('renames a shortcode through the form and persists it', function (): void {
        $staff = User::factory()->admin()->create();
        $thumbsup = Emoji::query()->where('shortcode', 'thumbsup')->sole();

        $this->actingAs($staff);

        // The Save button reads three x-refs. Calling updateEmoji() directly in a feature test cannot catch a
        // mis-named ref, so the click path is exercised here.
        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->type('@emoji-shortcode-'.$thumbsup->id, '+1')
            ->click('@emoji-save-thumbsup')
            // Every data-test on the row is keyed by shortcode, so the old key going away is the rename landing.
            // Asserted as an absence rather than as '@emoji-save-+1', which is not a parsable CSS selector.
            ->assertNotPresent('@emoji-save-thumbsup')
            ->assertNoJavaScriptErrors();

        expect($thumbsup->fresh()->shortcode)->toBe('+1');
    });

    // A rejected rename changes nothing in the DOM, so a browser test can only gate on the toast - and that is the
    // one thing here that has proven unreliable under CI load. The refusal is covered in
    // tests/Feature/Livewire/Admin/StaffToolsEmojiToolTest.php ('refuses a shortcode already used by another emoji'),
    // which asserts both the untouched row and the toast. The Save button's x-ref wiring is still exercised by the
    // rename test above, which clicks the same button.
});

describe('Staff emoji surface columns', function (): void {
    it('shows a column per surface', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->assertSee('Comment text')
            ->assertSee('Comment reacts')
            ->assertSee('Mod reacts')
            ->assertSee('Mod description')
            ->assertPresent('@emoji-surface-fire-comments')
            ->assertPresent('@emoji-surface-fire-comment_reactions')
            ->assertPresent('@emoji-surface-fire-mod_reactions')
            ->assertPresent('@emoji-surface-fire-mod_description')
            ->assertNoJavaScriptErrors();
    });

    it('restricts a surface when its checkbox is clicked', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        expect($fire->allow_comment_reactions)->toBeTrue();

        $page = visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-surface-fire-comment_reactions')
            ->assertNoJavaScriptErrors();

        // A native checkbox flips the instant it is clicked, so its state says nothing about whether the request
        // landed, and the row looks identical either way. Wait for the write itself, through the page: that yields
        // to the in-process server, where a plain sleep would block the very request being waited on.
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline && $fire->fresh()->allow_comment_reactions) {
            $page->wait(0.1);
        }

        // Only the surface clicked. Comment text is its own column, so it must be untouched by a reacts toggle.
        $fire->refresh();

        expect($fire->allow_comment_reactions)->toBeFalse()
            ->and($fire->allow_comments)->toBeTrue()
            ->and($fire->allow_mod_reactions)->toBeTrue()
            ->and($fire->enabled)->toBeTrue();
    });
});
