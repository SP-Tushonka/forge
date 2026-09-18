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
            ->waitForText('Saved')
            ->assertNoJavaScriptErrors();

        expect($thumbsup->fresh()->shortcode)->toBe('+1');
    });

    it('leaves the shortcode alone when the new one is already taken', function (): void {
        $staff = User::factory()->admin()->create();
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        $this->actingAs($staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->type('@emoji-shortcode-'.$fire->id, 'heart')
            ->click('@emoji-save-fire')
            ->waitForText('Shortcode in use')
            ->assertNoJavaScriptErrors();

        expect($fire->fresh()->shortcode)->toBe('fire');
    });
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

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-surface-fire-comment_reactions')
            ->waitForText('Saved')
            ->assertNoJavaScriptErrors();

        // Only the surface clicked. Comment text is its own column, so it must be untouched by a reacts toggle.
        $fire->refresh();

        expect($fire->allow_comment_reactions)->toBeFalse()
            ->and($fire->allow_comments)->toBeTrue()
            ->and($fire->allow_mod_reactions)->toBeTrue()
            ->and($fire->enabled)->toBeTrue();
    });
});
