<?php

declare(strict_types=1);

use App\Enums\EmojiSurface;
use App\Models\Comment;
use App\Models\Emoji;
use App\Models\Mod;
use App\Models\Reaction;
use App\Models\User;
use App\Services\ReactionSummaryService;
use App\Support\Markdown\EmojiRenderContext;
use GrahamCampbell\Markdown\Facades\Markdown;

beforeEach(function (): void {
    resolve(ReactionSummaryService::class)->forgetWhitelist();
});

describe('Emoji surface defaults', function (): void {
    it('allows every surface on a newly seeded emoji', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        foreach (EmojiSurface::ordered() as $surface) {
            expect($fire->allowsOn($surface))->toBeTrue($surface->value.' should default to allowed');
        }
    });

    it('offers every emoji on every surface until one is restricted', function (): void {
        $service = resolve(ReactionSummaryService::class);

        foreach (EmojiSurface::ordered() as $surface) {
            expect($service->whitelistFor($surface)->pluck('shortcode')->all())
                ->toBe($service->whitelist()->pluck('shortcode')->all());
        }
    });
});

describe('Emoji surface filtering', function (): void {
    it('withholds an emoji only from the surface it is restricted on', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update(['allow_comments' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $service = resolve(ReactionSummaryService::class);

        expect($service->whitelistFor(EmojiSurface::Comments)->pluck('shortcode')->all())->not->toContain('fire')
            ->and($service->whitelistFor(EmojiSurface::CommentReactions)->pluck('shortcode')->all())->toContain('fire')
            ->and($service->whitelistFor(EmojiSurface::ModReactions)->pluck('shortcode')->all())->toContain('fire')
            ->and($service->whitelistFor(EmojiSurface::ModDescription)->pluck('shortcode')->all())->toContain('fire');
    });

    it('separates writing a shortcode in a comment from reacting to one', function (): void {
        // The two comment surfaces are independent: an emoji can be reactable on comments while its shortcode is
        // withheld from comment text, and the other way round.
        Emoji::query()->where('shortcode', 'fire')->update(['allow_comments' => false]);
        Emoji::query()->where('shortcode', 'tada')->update(['allow_comment_reactions' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $service = resolve(ReactionSummaryService::class);

        expect($service->whitelistFor(EmojiSurface::Comments)->pluck('shortcode')->all())
            ->not->toContain('fire')->toContain('tada')
            ->and($service->whitelistFor(EmojiSurface::CommentReactions)->pluck('shortcode')->all())
            ->toContain('fire')->not->toContain('tada');
    });

    it('keeps a restricted emoji out of every surface list when all three are off', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update([
            'allow_comments' => false,
            'allow_comment_reactions' => false,
            'allow_mod_reactions' => false,
            'allow_mod_description' => false,
        ]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $service = resolve(ReactionSummaryService::class);

        foreach (EmojiSurface::ordered() as $surface) {
            expect($service->whitelistFor($surface)->pluck('shortcode')->all())->not->toContain('fire');
        }

        // Still enabled, so the master list is unchanged - a surface restriction narrows, it does not disable.
        expect($service->whitelist()->pluck('shortcode')->all())->toContain('fire');
    });

    it('never deletes reactions left with a restricted emoji', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        Emoji::query()->whereKey($fire->id)->update(['allow_mod_reactions' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(1);
    });
});

describe('Emoji surface restrictions in markdown', function (): void {
    it('shows the shortcode as plain text in a comment when the emoji is off there', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update(['allow_comments' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $html = EmojiRenderContext::scoped(
            EmojiSurface::Comments,
            fn (): string => Markdown::convert('that build is :fire:')->getContent(),
        );

        // The vendor extension would still resolve :fire:, so a bare "no image" assertion would pass for the wrong
        // reason. What matters is that the glyph is gone and the text the author typed survives.
        expect($html)->toContain(':fire:')->not->toContain('🔥');
    });

    it('still renders the emoji outside a restricted surface', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update(['allow_comments' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        // A mod description carries no surface, so nothing is withheld.
        expect(Markdown::convert('that build is :fire:')->getContent())->toContain('🔥');
    });

    it('clears the surface again even when rendering throws', function (): void {
        // Under Octane the worker outlives the request, so a leaked surface would restrict the next page it renders.
        try {
            EmojiRenderContext::scoped(EmojiSurface::Comments, function (): string {
                throw new RuntimeException('render blew up');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(EmojiRenderContext::current())->toBeNull();
    });

    it('restores the outer surface when scopes nest', function (): void {
        $inner = EmojiRenderContext::scoped(
            EmojiSurface::Comments,
            fn (): ?EmojiSurface => EmojiRenderContext::scoped(
                EmojiSurface::ModReactions,
                fn (): ?EmojiSurface => EmojiRenderContext::current(),
            ),
        );

        expect($inner)->toBe(EmojiSurface::ModReactions)
            ->and(EmojiRenderContext::current())->toBeNull();
    });

    it('renders a restricted emoji as its shortcode through the comment model itself', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update(['allow_comments' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $comment = Comment::factory()->create(['body' => 'that build is :fire:']);

        expect($comment->body_html)->toContain(':fire:')->not->toContain('🔥');
    });
});

describe('Emoji surface columns against a stale cache', function (): void {
    it('treats an emoji hydrated without the surface columns as allowed everywhere', function (): void {
        // Exactly what a whitelist payload cached before the migration rehydrates into: the pre-migration column
        // set and nothing more. Reading a surface off that threw MissingAttributeException under strict mode and
        // took every page that renders a reaction down with it.
        $stale = Emoji::hydrate([[
            'id' => 1,
            'shortcode' => 'heart',
            'glyph' => '❤️',
            'codepoints' => '2764',
            'label' => 'Heart',
            'sort_order' => 1,
            'enabled' => true,
            'image_path' => null,
            'image_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]])->sole();

        foreach (EmojiSurface::ordered() as $surface) {
            expect($stale->allowsOn($surface))->toBeTrue($surface->value.' should fall back to allowed');
        }
    });

    it('still reads a real value when the column is present', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $fire->update(['allow_mod_description' => false]);

        // Non-vacuous: the fallback above must not be swallowing genuine restrictions.
        expect($fire->refresh()->allowsOn(EmojiSurface::ModDescription))->toBeFalse()
            ->and($fire->allowsOn(EmojiSurface::Comments))->toBeTrue();
    });
});

describe('Emoji surface restrictions in mod descriptions', function (): void {
    it('shows the shortcode as plain text in a mod description when the emoji is off there', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update(['allow_mod_description' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $mod = Mod::factory()->create(['description' => 'this build is :fire:']);

        expect($mod->description_html)->toContain(':fire:')->not->toContain('🔥');
    });

    it('still renders it in a comment when only the description is restricted', function (): void {
        Emoji::query()->where('shortcode', 'fire')->update(['allow_mod_description' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        // The two text surfaces are independent, so a description restriction must not reach comment bodies.
        $comment = Comment::factory()->create(['body' => 'this build is :fire:']);

        expect($comment->body_html)->toContain('🔥');
    });

    it('renders the description normally while the emoji is allowed there', function (): void {
        // Non-vacuous: proves the assertions above turn on the column rather than on the emoji never rendering.
        $mod = Mod::factory()->create(['description' => 'this build is :fire:']);

        expect($mod->description_html)->toContain('🔥');
    });
});
