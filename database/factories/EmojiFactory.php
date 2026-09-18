<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Emoji;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Emoji>
 */
final class EmojiFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shortcode = 'test_'.$this->faker->unique()->word();

        // Drawn from the emoticon block (U+1F600-1F64F): every codepoint there is a single-codepoint emoji that
        // Twemoji ships, and taking a unique one keeps the emojis.codepoints unique index satisfied.
        $codepoint = 0x1F600 + $this->faker->unique()->numberBetween(0, 0x4F);

        return [
            'shortcode' => $shortcode,
            'glyph' => mb_chr($codepoint),
            'codepoints' => dechex($codepoint),
            'label' => ucfirst(str_replace('_', ' ', $shortcode)),
            'sort_order' => $this->faker->numberBetween(10, 99),
            'enabled' => true,
        ];
    }

    /**
     * A staff-uploaded emoji: artwork on the asset disk in place of a Unicode codepoint.
     */
    public function custom(): self
    {
        return $this->state(fn (): array => [
            'glyph' => null,
            'codepoints' => null,
            'image_path' => 'emoji/'.$this->faker->unique()->sha1().'.webp',
            'image_hash' => hash('sha256', (string) $this->faker->unique()->uuid()),
        ]);
    }

    /**
     * A retired emoji: still present, no longer offered.
     */
    public function disabled(): self
    {
        return $this->state(['enabled' => false]);
    }
}
