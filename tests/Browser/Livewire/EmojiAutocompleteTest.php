<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    config(['honeypot.enabled' => false]);

    SptVersion::factory()->create(['version' => '1.0.0']);

    $this->mod = Mod::factory()->create(['published_at' => now()->subHour()]);
    ModVersion::factory()->recycle($this->mod)->create(['spt_version_constraint' => '1.0.0']);
});

/**
 * The comment box on a mod's detail page. x-show keeps the menu in the DOM, so assertMissing is the visibility check.
 */
$box = '@new-comment-body';

describe('Emoji autocomplete', function () use ($box): void {
    it('opens on a colon followed by a shortcode and inserts the markdown form', function () use ($box): void {
        $this->actingAs(User::factory()->create());

        visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type($box, 'Nice work :fi')
            ->assertVisible('@emoji-autocomplete')
            ->assertPresent('@emoji-autocomplete-option-fire')
            ->keys($box, ['Enter'])
            ->assertMissing('@emoji-autocomplete')
            ->assertValue($box, 'Nice work :fire: ')
            ->assertNoJavaScriptErrors();
    });

    it('paints above the comments below it', function () use ($box): void {
        $author = User::factory()->create();

        // A comment underneath is what the menu was rendering behind.
        App\Models\Comment::factory()->create([
            'commentable_id' => $this->mod->id,
            'commentable_type' => Mod::class,
            'user_id' => $author->id,
            'body' => 'A comment sitting under the editor.',
        ]);

        $this->actingAs(User::factory()->create());

        $page = visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type($box, 'test :h')
            ->assertVisible('@emoji-autocomplete');

        // elementFromPoint returns whatever the user would actually click at that spot. If a comment card is painted
        // over the menu, the hit test lands on the card instead and this fails - which asserting visibility cannot
        // catch, since the menu is "visible" either way.
        $script = <<<'JS'
            (() => {
                const menu = document.querySelector('[data-test="emoji-autocomplete"]');
                if (! menu) { return 'menu missing'; }

                const box = menu.getBoundingClientRect();
                if (box.width === 0 || box.height === 0) { return 'menu has no size'; }

                const x = box.left + (box.width / 2);
                const y = box.bottom - 4;
                const hit = document.elementFromPoint(x, y);

                return menu.contains(hit) ? true : 'covered by ' + (hit ? hit.className : 'nothing');
            })()
            JS;

        $page->assertScript($script, true)->assertNoJavaScriptErrors();
    });

    it('does not open for a colon that ends a sentence', function () use ($box): void {
        $this->actingAs(User::factory()->create());

        // The reported false positive: the colon follows "?" rather than whitespace.
        visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type($box, 'Hey, can you check this out?:')
            ->assertMissing('@emoji-autocomplete')
            ->assertNoJavaScriptErrors();
    });

    it('does not open for a time, a url or a label colon', function () use ($box): void {
        $this->actingAs(User::factory()->create());

        $page = visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment');

        foreach (['Meet at 10:30', 'See https://example.com', 'note: something'] as $text) {
            $page->clear($box)
                ->type($box, $text)
                ->assertMissing('@emoji-autocomplete');
        }

        $page->assertNoJavaScriptErrors();
    });

    it('does not open for a bare colon after a space', function () use ($box): void {
        $this->actingAs(User::factory()->create());

        visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type($box, 'waiting :')
            ->assertMissing('@emoji-autocomplete')
            ->assertNoJavaScriptErrors();
    });

    it('closes again when the shortcode matches nothing', function () use ($box): void {
        $this->actingAs(User::factory()->create());

        visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type($box, 'hmm :zzzzz')
            ->assertMissing('@emoji-autocomplete')
            ->assertNoJavaScriptErrors();
    });

    it('dismisses on escape without inserting anything', function () use ($box): void {
        $this->actingAs(User::factory()->create());

        visit($this->mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type($box, 'Nice work :fi')
            ->assertVisible('@emoji-autocomplete')
            ->keys($box, ['Escape'])
            ->assertMissing('@emoji-autocomplete')
            ->assertValue($box, 'Nice work :fi')
            ->assertNoJavaScriptErrors();
    });
});
