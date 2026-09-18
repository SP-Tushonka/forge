<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmojiSurface;
use Carbon\CarbonImmutable;
use Database\Factories\EmojiFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $shortcode
 * @property string|null $glyph
 * @property string|null $codepoints
 * @property string $label
 * @property string|null $image_path
 * @property string|null $image_hash
 * @property int $sort_order
 * @property bool $enabled
 * @property bool $allow_comments
 * @property bool $allow_comment_reactions
 * @property bool $allow_mod_reactions
 * @property bool $allow_mod_description
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read string $image_url
 * @property-read string $alt_text
 * @property-read Collection<int, Reaction> $reactions
 */
final class Emoji extends Model
{
    /** @use HasFactory<EmojiFactory> */
    use HasFactory;

    /**
     * Set explicitly: Laravel's inflector treats "emoji" as uncountable, so it would resolve the table to "emoji"
     * while every other table in this schema is plural.
     */
    protected $table = 'emojis';

    /**
     * Every reaction that uses this emoji. Guarded by a restrictOnDelete foreign key, so the only thing that may
     * remove these rows is the staff delete control, which clears them explicitly inside a transaction.
     *
     * @return HasMany<Reaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    /**
     * Whether this is staff-uploaded artwork rather than a Twemoji codepoint. Derived rather than stored: a `kind`
     * column could drift out of step with the columns describing it.
     */
    public function isCustom(): bool
    {
        return $this->image_path !== null;
    }

    /**
     * Whether staff allow this emoji on a given surface. Independent of `enabled`, which switches it off everywhere
     * at once; a surface restriction narrows where an otherwise-enabled emoji may appear.
     */
    public function allowsOn(EmojiSurface $surface): bool
    {
        $column = $surface->column();

        // A whitelist payload cached before these columns existed rehydrates without them, and strict mode turns
        // that into a 500 rather than a quiet null. Falling back to the column default means a stale cache offers
        // the emoji a little too widely until it expires, instead of taking every page with it.
        if (! array_key_exists($column, $this->getAttributes())) {
            return true;
        }

        return (bool) $this->getAttribute($column);
    }

    /**
     * Only the emoji staff have left switched on, in the order staff chose.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function enabled(Builder $query): void
    {
        $query->where('enabled', true)->orderBy('sort_order');
    }

    /**
     * The artwork URL: an uploaded WebP for a custom emoji, otherwise the bundled Twemoji SVG. Both resolve to
     * forge-static.sp-mod.com in production.
     *
     * @return Attribute<string, never>
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->image_path !== null
                ? Storage::disk(config()->string('filesystems.asset_upload', 'public'))->url($this->image_path)
                : asset('vendor/twemoji/svg/'.$this->codepoints.'.svg'),
        )->shouldCache();
    }

    /**
     * Alt text for the artwork. A custom emoji has no glyph to fall back on, so its shortcode stands in.
     *
     * @return Attribute<string, never>
     */
    protected function altText(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->glyph ?? ':'.$this->shortcode.':',
        )->shouldCache();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'enabled' => 'boolean',
            'allow_comments' => 'boolean',
            'allow_comment_reactions' => 'boolean',
            'allow_mod_reactions' => 'boolean',
            'allow_mod_description' => 'boolean',
        ];
    }
}
