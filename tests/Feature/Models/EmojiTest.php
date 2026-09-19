<?php

declare(strict_types=1);

use App\Models\Emoji;
use Illuminate\Support\Facades\Storage;

describe('Emoji whitelist', function (): void {
    it('is seeded with the five starter emoji in order', function (): void {
        expect(Emoji::query()->orderBy('sort_order')->pluck('shortcode')->all())
            ->toBe(['heart', 'thumbsup', 'joy', 'fire', 'tada']);
    });

    it('stores the heart without the variation selector so the twemoji filename resolves', function (): void {
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();

        expect($heart->codepoints)->toBe('2764')
            ->and($heart->glyph)->toBe('❤️')
            ->and($heart->enabled)->toBeTrue();
    });

    it('builds the image url from the stored codepoints', function (): void {
        // Not one of the starter codepoints: emojis.codepoints is unique, so an emoji may only appear once.
        $emoji = Emoji::factory()->create(['codepoints' => '1f680']);

        expect($emoji->image_url)->toBe(asset('vendor/twemoji/svg/1f680.svg'))
            ->and($emoji->isCustom())->toBeFalse();
    });

    it('refuses a second emoji with the same codepoints', function (): void {
        expect(fn () => Emoji::factory()->create(['codepoints' => '2764']))
            ->toThrow(Illuminate\Database\QueryException::class);
    });

    it('excludes disabled emoji from the enabled scope', function (): void {
        Emoji::query()->where('shortcode', 'tada')->update(['enabled' => false]);

        expect(Emoji::query()->enabled()->pluck('shortcode')->all())
            ->toBe(['heart', 'thumbsup', 'joy', 'fire']);
    });
});

describe('Custom emoji', function (): void {
    it('serves uploaded artwork from the asset disk', function (): void {
        $emoji = Emoji::factory()->custom()->create(['image_path' => 'emoji/abc.webp']);

        $disk = config()->string('filesystems.asset_upload', 'public');

        expect($emoji->image_url)->toBe(Storage::disk($disk)->url('emoji/abc.webp'))
            ->and($emoji->isCustom())->toBeTrue();
    });

    it('falls back to the shortcode for alt text when there is no glyph', function (): void {
        $custom = Emoji::factory()->custom()->create(['shortcode' => 'partyblob']);
        $standard = Emoji::query()->where('shortcode', 'fire')->sole();

        expect($custom->alt_text)->toBe(':partyblob:')
            ->and($standard->alt_text)->toBe('🔥');
    });

    it('allows many custom emoji, none of which have codepoints', function (): void {
        Emoji::factory()->custom()->count(3)->create();

        // Repeated NULLs are permitted by a unique index; only real codepoints collide.
        expect(Emoji::query()->whereNull('codepoints')->count())->toBe(3);
    });

    it('refuses two emoji with the same artwork', function (): void {
        $hash = hash('sha256', 'identical-bytes');

        Emoji::factory()->custom()->create(['image_hash' => $hash]);

        expect(fn () => Emoji::factory()->custom()->create(['image_hash' => $hash]))
            ->toThrow(Illuminate\Database\QueryException::class);
    });
});
