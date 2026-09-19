<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\Reaction;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::factory()->create(['version' => '1.0.0']);

    $this->heart = Emoji::query()->where('shortcode', 'heart')->sole();
});

/**
 * Mods that are actually visible on the browse page.
 *
 * @return Collection<int, Mod>
 */
function reactableMods(int $count): Collection
{
    $mods = Mod::factory()->count($count)->create([
        'disabled' => false,
        'published_at' => now()->subHour(),
    ]);

    foreach ($mods as $mod) {
        ModVersion::factory()->recycle($mod)->create([
            'version' => '1.0.0',
            'spt_version_constraint' => '1.0.0',
        ]);
    }

    return $mods;
}

describe('mod card reactions', function (): void {
    it('renders a read-only summary on browse cards', function (): void {
        $mod = reactableMods(1)->sole();

        foreach (User::factory()->count(3)->create() as $reactor) {
            $mod->reactions()->create(['user_id' => $reactor->id, 'emoji_id' => $this->heart->id]);
        }

        $this->get('/mods')
            ->assertOk()
            ->assertSee('reaction-summary-'.$mod->id, false)
            ->assertDontSee('reaction-bar-mod-'.$mod->id, false)
            ->assertDontSee('reaction-add-'.$mod->id, false);
    });

    it('shows the most used emoji as the representative glyph with the overall total', function (): void {
        $mod = reactableMods(1)->sole();
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        // Fire wins 3 to 1, so it represents the card even though heart sorts first in the whitelist.
        foreach (User::factory()->count(3)->create() as $reactor) {
            $mod->reactions()->create(['user_id' => $reactor->id, 'emoji_id' => $fire->id]);
        }
        $mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => $this->heart->id,
        ]);

        $html = (string) $this->get('/mods')->assertOk()->getContent();

        $summaryAt = mb_strpos($html, 'reaction-summary-'.$mod->id);
        expect($summaryAt)->not->toBeFalse();

        $summary = mb_substr($html, (int) $summaryAt, 600);

        expect($summary)->toContain($fire->codepoints.'.svg')      // representative glyph is the top emoji
            ->and($summary)->toContain('>4<');                      // total across every emoji, not just the top one
    });

    it('renders nothing at all for a mod with no reactions', function (): void {
        $mod = reactableMods(1)->sole();

        $this->get('/mods')
            ->assertOk()
            ->assertDontSee('reaction-summary-'.$mod->id, false);
    });

    it('refuses a reaction aimed at a listing component', function (): void {
        $mod = reactableMods(1)->sole();
        $user = User::factory()->create();

        // The listing takes ProvidesReactionSummary only, so there is no toggleReaction endpoint to reach.
        expect(fn () => Livewire::actingAs($user)
            ->test('pages::mod.index')
            ->call('toggleReaction', 'mod', $mod->id, $this->heart->id))
            ->toThrow(Exception::class);

        expect(Reaction::query()->count())->toBe(0);
    });

    it('renders the summary inside the card anchor, beside the other stats', function (): void {
        $mod = reactableMods(1)->sole();
        $mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => $this->heart->id,
        ]);

        $html = (string) $this->get('/mods')->assertOk()->getContent();

        $summaryAt = mb_strpos($html, 'reaction-summary-'.$mod->id);
        expect($summaryAt)->not->toBeFalse();

        // The read-only summary belongs INSIDE the card's anchor: that is what keeps it in the card box rather than
        // floating on the page background. An unclosed <a> before it is therefore what we want to see.
        $before = mb_substr($html, 0, (int) $summaryAt);

        $opened = preg_match_all('/<a[\s>]/', $before);
        $closed = mb_substr_count($before, '</a>');

        expect($opened)->toBeGreaterThan($closed);
    });

    it('puts no title attribute on reaction markup, so no native tooltip fights the styled one', function (): void {
        $mod = reactableMods(1)->sole();
        $mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => $this->heart->id,
        ]);

        $html = (string) $this->get('/mods')->assertOk()->getContent();

        $summaryAt = mb_strpos($html, 'reaction-summary-'.$mod->id);
        expect($summaryAt)->not->toBeFalse();

        // Look at the summary element's own tag only, not the surrounding stats which legitimately use title.
        $tag = mb_substr($html, (int) $summaryAt - 400, 400 + 200);

        expect($tag)->not->toContain('title="')
            ->and($tag)->toContain('aria-label=');
    });

    it('adds a bounded number of reaction queries regardless of card count', function (): void {
        $mods = reactableMods(12);
        $user = User::factory()->create();

        foreach ($mods as $mod) {
            $mod->reactions()->create(['user_id' => $user->id, 'emoji_id' => $this->heart->id]);
        }

        // Warm every other cache the page uses so only reaction queries are left to count.
        $this->actingAs($user)->get('/mods')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($user)->get('/mods')->assertOk();

        $reactionQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains((string) $entry['query'], '"reactions"')
                || str_contains((string) $entry['query'], '`reactions`'))
            ->count();

        expect($reactionQueries)->toBeLessThanOrEqual(3);
    });
});

describe('mod card reaction restrictions', function (): void {
    // The card figure has no permission of its own: it is a rollup of mod reactions and follows that column, so an
    // emoji kept off mod reactions stops counting towards the number shown for them.
    it('drops a restricted emoji from the card total, not merely from the icon', function (): void {
        $mod = reactableMods(1)->sole();
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        // Fire outnumbers heart 3 to 1 and would otherwise both represent the card and dominate the total.
        foreach (User::factory()->count(3)->create() as $reactor) {
            $mod->reactions()->create(['user_id' => $reactor->id, 'emoji_id' => $fire->id]);
        }
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->heart->id]);

        Emoji::query()->whereKey($fire->id)->update(['allow_mod_reactions' => false]);
        resolve(App\Services\ReactionSummaryService::class)->forgetWhitelist();

        $html = (string) $this->get('/mods')->assertOk()->getContent();

        $summaryAt = mb_strpos($html, 'reaction-summary-'.$mod->id);
        expect($summaryAt)->not->toBeFalse();

        $summary = mb_substr($html, (int) $summaryAt, 600);

        // One reaction counted, not four, and heart stands in as the representative.
        expect($summary)->toContain($this->heart->codepoints.'.svg')
            ->not->toContain($fire->codepoints.'.svg')
            ->and($summary)->toContain('>1<');

        // The rows themselves survive: switching the surface back on must restore the figure exactly.
        expect(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(3);
    });

    it('renders no summary at all when every reaction is with a restricted emoji', function (): void {
        $mod = reactableMods(1)->sole();

        foreach (User::factory()->count(2)->create() as $reactor) {
            $mod->reactions()->create(['user_id' => $reactor->id, 'emoji_id' => $this->heart->id]);
        }

        Emoji::query()->whereKey($this->heart->id)->update(['allow_mod_reactions' => false]);
        resolve(App\Services\ReactionSummaryService::class)->forgetWhitelist();

        $this->get('/mods')
            ->assertOk()
            ->assertDontSee('reaction-summary-'.$mod->id, false);
    });

    it('takes the emoji off the detail bar and the card together', function (): void {
        $mod = reactableMods(1)->sole();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->heart->id]);

        // Non-vacuous: both surfaces show it before the restriction, so the assertions below cannot pass by the
        // markup simply being absent for some other reason.
        $this->get($mod->detail_url)->assertOk()->assertSee('reaction-chip-'.$mod->id.'-heart', false);
        $this->get('/mods')->assertOk()->assertSee('reaction-summary-'.$mod->id, false);

        Emoji::query()->whereKey($this->heart->id)->update(['allow_mod_reactions' => false]);
        resolve(App\Services\ReactionSummaryService::class)->forgetWhitelist();

        $this->get($mod->detail_url)->assertOk()->assertDontSee('reaction-chip-'.$mod->id.'-heart', false);
        $this->get('/mods')->assertOk()->assertDontSee('reaction-summary-'.$mod->id, false);
    });

    it('leaves the mod reaction bar alone when only the description is restricted', function (): void {
        $mod = reactableMods(1)->sole();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->heart->id]);

        Emoji::query()->whereKey($this->heart->id)->update(['allow_mod_description' => false]);
        resolve(App\Services\ReactionSummaryService::class)->forgetWhitelist();

        // Description text and reacting are separate surfaces, so restricting one must not touch the other.
        $this->get($mod->detail_url)
            ->assertOk()
            ->assertSee('reaction-chip-'.$mod->id.'-heart', false);
    });
});
