<?php

declare(strict_types=1);

namespace App\Support\Markdown;

use App\Enums\EmojiSurface;
use Closure;

/**
 * Tells WhitelistEmojiParser which surface it is rendering for.
 *
 * CommonMark's environment is built once and shared, so the parser cannot tell a comment from a mod description on
 * its own. Comment rendering wraps itself in scoped() to say so; everything else renders with no surface set and is
 * therefore unrestricted, which is what keeps a Comments restriction from quietly changing mod descriptions.
 *
 * The surface is always cleared in a finally block. That matters under Octane, where a worker outlives the request:
 * a leaked surface would restrict the next page rendered by the same worker.
 */
final class EmojiRenderContext
{
    private static ?EmojiSurface $surface = null;

    /**
     * Render inside a surface, restoring whatever was set before. Nesting is supported so a comment rendered within
     * another render does not clear the outer context on the way out.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function scoped(EmojiSurface $surface, Closure $callback): mixed
    {
        $previous = self::$surface;
        self::$surface = $surface;

        try {
            return $callback();
        } finally {
            self::$surface = $previous;
        }
    }

    /**
     * The surface currently being rendered, or null when rendering is unrestricted.
     */
    public static function current(): ?EmojiSurface
    {
        return self::$surface;
    }
}
