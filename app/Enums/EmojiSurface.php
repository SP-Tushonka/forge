<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The places a reaction emoji may appear. Staff restrict each one independently, so an emoji can be offered on mod
 * detail pages while being kept out of comments entirely.
 *
 * Two of them govern text rather than reacting - a shortcode written into a comment or into a mod's description -
 * and a restriction there leaves the shortcode on the page as the author typed it.
 *
 * The summarised figure on mod cards has no surface of its own: it is a rollup of mod reactions and follows that
 * permission, so an emoji kept off mod reactions leaves the card totals too.
 */
enum EmojiSurface: string
{
    /** Comment text: :shortcode: in a comment body, and the shortcode autocomplete that suggests it. */
    case Comments = 'comments';

    /** The reaction bar beneath a comment. */
    case CommentReactions = 'comment_reactions';

    /** The interactive reaction bar on a mod's detail page. */
    case ModReactions = 'mod_reactions';

    /** A mod's description text: :shortcode: written into it. */
    case ModDescription = 'mod_description';

    /**
     * Every surface, in the order the staff table shows them.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        return [self::Comments, self::CommentReactions, self::ModReactions, self::ModDescription];
    }

    /**
     * The emojis column holding this surface's permission.
     */
    public function column(): string
    {
        return match ($this) {
            self::Comments => 'allow_comments',
            self::CommentReactions => 'allow_comment_reactions',
            self::ModReactions => 'allow_mod_reactions',
            self::ModDescription => 'allow_mod_description',
        };
    }

    /**
     * The column heading staff see in Staff Tools.
     */
    public function label(): string
    {
        return match ($this) {
            self::Comments => 'Comment text',
            self::CommentReactions => 'Comment reacts',
            self::ModReactions => 'Mod reacts',
            self::ModDescription => 'Mod description',
        };
    }
}
