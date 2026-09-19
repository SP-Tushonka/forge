<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Services\ReactionSummaryService;
use GrahamCampbell\Markdown\Facades\Markdown;
use Stevebauman\Purify\Facades\Purify;

function renderMarkdown(string $body): string
{
    return Markdown::convert($body)->getContent();
}

beforeEach(function (): void {
    resolve(ReactionSummaryService::class)->forgetWhitelist();
});

describe('whitelist shortcodes in markdown', function (): void {
    it('renders a shortcode assigned by staff', function (): void {
        Emoji::query()->create([
            'shortcode' => 'gold',
            'glyph' => '🥇',
            'codepoints' => '1f947',
            'label' => 'Gold',
            'sort_order' => 10,
            'enabled' => true,
        ]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect(renderMarkdown('Well done :gold:'))->toContain('🥇');
    });

    it('renders a shortcode renamed away from the vendor name', function (): void {
        // Exactly the reported case: :1st_place_medal: renamed to :gold:.
        $medal = Emoji::query()->create([
            'shortcode' => '1st_place_medal',
            'glyph' => '🥇',
            'codepoints' => '1f947',
            'label' => 'Gold',
            'sort_order' => 10,
            'enabled' => true,
        ]);

        $medal->update(['shortcode' => 'gold']);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect(renderMarkdown(':gold:'))->toContain('🥇');
    });

    it('leaves the vendor shortcodes working', function (): void {
        // Not in the whitelist at all, so the vendor parser must still handle it.
        expect(renderMarkdown('Ship it :rocket:'))->toContain('🚀');
    });

    it('still renders a starter whitelist shortcode', function (): void {
        expect(renderMarkdown(':fire:'))->toContain('🔥');
    });

    it('leaves an unknown shortcode as literal text', function (): void {
        expect(renderMarkdown(':definitely_not_an_emoji:'))->toContain(':definitely_not_an_emoji:');
    });

    it('stops resolving a shortcode once its emoji is disabled', function (): void {
        $emoji = Emoji::query()->create([
            'shortcode' => 'gold',
            'glyph' => '🥇',
            'codepoints' => '1f947',
            'label' => 'Gold',
            'sort_order' => 10,
            'enabled' => true,
        ]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect(renderMarkdown(':gold:'))->toContain('🥇');

        $emoji->update(['enabled' => false]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        // Disabling hides an emoji everywhere, markdown included.
        expect(renderMarkdown(':gold:'))->toContain(':gold:');
    });

    it('lets a staff shortcode take precedence over the vendor map', function (): void {
        Emoji::query()->create([
            'shortcode' => 'rocket',
            'glyph' => '🥇',
            'codepoints' => '1f947',
            'label' => 'Gold',
            'sort_order' => 10,
            'enabled' => true,
        ]);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect(renderMarkdown(':rocket:'))->toContain('🥇')
            ->and(renderMarkdown(':rocket:'))->not->toContain('🚀');
    });
});

describe('custom emoji in markdown', function (): void {
    it('renders uploaded artwork as an image', function (): void {
        Emoji::factory()->custom()->create(['shortcode' => 'partyblob', 'image_path' => 'emoji/party.webp']);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $html = renderMarkdown('Nice :partyblob:');

        expect($html)->toContain('<img')
            ->and($html)->toContain('class="emoji"')
            ->and($html)->toContain('alt=":partyblob:"')
            ->and($html)->toContain('party.webp');
    });

    it('leaves a disabled custom emoji as plain text', function (): void {
        Emoji::factory()->custom()->disabled()->create(['shortcode' => 'partyblob']);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        expect(renderMarkdown('Nice :partyblob:'))
            ->toContain(':partyblob:')
            ->not->toContain('<img');
    });

    it('still renders a standard emoji as unicode rather than an image', function (): void {
        $html = renderMarkdown('Nice :fire:');

        expect($html)->toContain('🔥')->not->toContain('<img');
    });

    it('carries the rendered size on the tag, not the stored size', function (): void {
        Emoji::factory()->custom()->create(['shortcode' => 'partyblob']);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        // If the class is ever stripped these attributes are the fallback, so a 128 here would drop a full-size
        // image into the middle of a sentence.
        expect(renderMarkdown(':partyblob:'))
            ->toContain('width="22"')
            ->toContain('height="22"');
    });

    it('survives both purify profiles with its class intact', function (): void {
        Emoji::factory()->custom()->create(['shortcode' => 'partyblob', 'image_path' => 'emoji/party.webp']);
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        $html = renderMarkdown('Nice :partyblob:');

        // Purify drops unlisted attributes and unknown classes, so without the config change the image would reach
        // the page unstyled and render at its intrinsic size.
        foreach (['comments', 'description'] as $profile) {
            expect(Purify::config($profile)->clean($html))
                ->toContain('class="emoji"')
                ->toContain('party.webp');
        }
    });
});
