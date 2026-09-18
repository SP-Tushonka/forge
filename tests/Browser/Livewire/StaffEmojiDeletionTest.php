<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    $this->staff = User::factory()->admin()->create();
});

describe('Staff emoji deletion', function (): void {
    it('spells out what deleting would destroy', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-delete-fire')
            ->waitForText('Delete this emoji?')
            ->assertSee('The one reaction left with it is deleted for good.')
            // :fire: is a standard shortcode, so markdown keeps working without us.
            ->assertSee('carry on working')
            ->assertSee('None of this can be undone')
            ->assertNoJavaScriptErrors();
    });

    it('warns that comments lose the emoji when staff invented the shortcode', function (): void {
        $medal = Emoji::query()->where('shortcode', 'tada')->sole();
        $medal->update(['shortcode' => 'gold']);

        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-delete-gold')
            ->waitForText('Delete this emoji?')
            ->assertSee('will show that plain text instead of the emoji')
            ->assertNoJavaScriptErrors();
    });

    it('keeps the confirm button disabled until delete is typed', function (): void {
        $this->actingAs($this->staff);

        $page = visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-delete-fire')
            ->waitForText('Delete this emoji?');

        $disabled = 'document.querySelector(\'[data-test="emoji-delete-confirm"]\').disabled';

        $page->assertScript($disabled, true)
            ->type('@emoji-delete-confirmation', 'remove')
            ->assertScript($disabled, true)
            ->type('@emoji-delete-confirmation', 'delete')
            ->assertScript($disabled, false)
            ->assertNoJavaScriptErrors();
    });

    it('leaves the emoji alone when the modal is cancelled', function (): void {
        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-delete-fire')
            ->waitForText('Delete this emoji?')
            ->type('@emoji-delete-confirmation', 'delete')
            ->click('@emoji-delete-cancel')
            ->assertMissing('@emoji-delete-confirm')
            ->assertNoJavaScriptErrors();

        expect(Emoji::query()->where('shortcode', 'fire')->exists())->toBeTrue();
    });

    it('deletes the emoji and its reactions once confirmed', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->click('@emoji-delete-fire')
            ->waitForText('Delete this emoji?')
            ->type('@emoji-delete-confirmation', 'delete')
            ->click('@emoji-delete-confirm')
            ->assertMissing('@emoji-delete-fire')
            ->assertNoJavaScriptErrors();

        expect(Emoji::query()->whereKey($fire->id)->exists())->toBeFalse()
            ->and(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(0);
    });
});
