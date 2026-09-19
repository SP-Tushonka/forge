<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Enums\EmojiSurface;
use App\Models\Emoji;
use App\Services\ReactionSummaryService;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Node\Inline\AbstractInline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Resolves :shortcode: in markdown against the staff-curated reaction whitelist, so a shortcode renamed in Staff Tools
 * works in comments too rather than only in the reaction picker.
 *
 * Registered at a higher priority than ElGigi's EmojiParser and returns false for anything it does not recognise, so
 * the vendor's ~1,780 shortcodes keep working untouched. Only enabled emoji resolve: disabling one stops its shortcode
 * everywhere, which is what the staff panel promises.
 */
final class WhitelistEmojiParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::join(
            InlineParserMatch::string(':'),
            InlineParserMatch::regex('[\w\-+]+'),
            InlineParserMatch::string(':'),
        );
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();

        // Mirrors the vendor parser: the colon must not butt up against a preceding word character.
        $previousChar = $cursor->peek(-1);

        if ($previousChar !== null && $previousChar !== ' ') {
            return false;
        }

        $previousState = $cursor->saveState();

        $cursor->advance();

        $identifier = $cursor->match('/^[\w\-\+]+\:/i');

        if ($identifier === null) {
            $cursor->restoreState($previousState);

            return false;
        }

        $emoji = $this->whitelist()[mb_strtolower(mb_substr($identifier, 0, -1))] ?? null;

        if (! $emoji instanceof Emoji) {
            // Not one of ours: rewind and let the vendor parser have it.
            $cursor->restoreState($previousState);

            return false;
        }

        $surface = EmojiRenderContext::current();

        if ($surface instanceof EmojiSurface && ! $emoji->allowsOn($surface)) {
            // Staff have kept this emoji off this surface, so the text the author typed stands in for it. The match
            // is consumed rather than declined on purpose: rewinding would hand ":fire:" to the vendor parser, which
            // knows nothing of the restriction and would render the emoji anyway.
            $inlineContext->getContainer()->appendChild(new Text(':'.$identifier));

            return true;
        }

        $inlineContext->getContainer()->appendChild($this->node($emoji));

        return true;
    }

    /**
     * Deliberately not memoised on the instance. CommonMark's environment - and therefore this parser - outlives a
     * single request under Octane, so a cached map here would serve a stale whitelist until the worker restarted, and
     * a staff rename would appear not to take effect. The service's own cache absorbs the repeat cost.
     *
     * Every enabled emoji, surface restrictions included. Restricted ones must still be recognised here so that
     * parse() can consume them and emit their shortcode as text; filtering them out at this level would let the
     * vendor parser render them instead.
     *
     * @return array<string, Emoji>
     */
    private function whitelist(): array
    {
        $whitelist = [];

        foreach (resolve(ReactionSummaryService::class)->whitelist() as $emoji) {
            $whitelist[$emoji->shortcode] = $emoji;
        }

        return $whitelist;
    }

    /**
     * A Twemoji emoji becomes the Unicode character itself, which needs no artwork and survives being copied out of
     * a comment. A custom emoji has no character to emit, so it becomes an image sized to the surrounding text.
     */
    private function node(Emoji $emoji): AbstractInline
    {
        if (! $emoji->isCustom()) {
            return new Text((string) $emoji->glyph);
        }

        $image = new Image($emoji->image_url, ':'.$emoji->shortcode.':');
        $image->data->set('attributes/class', 'emoji');
        // The rendered size rather than the stored 128: these are what a browser falls back to if the class is ever
        // stripped, and a 128px emoji dropped mid-sentence is a far louder failure than a slightly wrong 22px one.
        $image->data->set('attributes/width', '22');
        $image->data->set('attributes/height', '22');

        return $image;
    }
}
