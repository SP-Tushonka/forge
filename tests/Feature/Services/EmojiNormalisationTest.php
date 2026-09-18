<?php

declare(strict_types=1);

use App\Services\ThumbnailService;

beforeEach(function (): void {
    // Herd's PHP 8.5 on Windows ships no imagick build, exactly as for avatar and thumbnail processing. These run
    // in CI; skipping is the correct local outcome, not a pass.
    if (! extension_loaded('imagick')) {
        $this->markTestSkipped('Imagick is not installed.');
    }
});

/**
 * A solid rectangle PNG built in memory, so the suite carries no binary fixtures.
 */
function emojiSource(int $width, int $height): string
{
    $image = new Imagick;
    $image->newImage($width, $height, new ImagickPixel('red'));
    $image->setImageFormat('png');

    return $image->getImageBlob();
}

/**
 * Decode a normalised blob for inspection.
 */
function decodeEmoji(?string $blob): Imagick
{
    $image = new Imagick;
    $image->readImageBlob((string) $blob);

    return $image;
}

describe('Emoji normalisation', function (): void {
    it('produces a square webp at the emoji width', function (): void {
        $result = decodeEmoji(resolve(ThumbnailService::class)->normalizeEmoji(emojiSource(512, 512)));

        expect($result->getImageWidth())->toBe(ThumbnailService::EMOJI_WIDTH)
            ->and($result->getImageHeight())->toBe(ThumbnailService::EMOJI_WIDTH)
            ->and(mb_strtolower($result->getImageFormat()))->toBe('webp');
    });

    it('crops a wide source to a square', function (): void {
        $result = decodeEmoji(resolve(ThumbnailService::class)->normalizeEmoji(emojiSource(512, 256)));

        expect($result->getImageWidth())->toBe($result->getImageHeight());
    });

    it('does not upscale a source smaller than the emoji width', function (): void {
        $result = decodeEmoji(resolve(ThumbnailService::class)->normalizeEmoji(emojiSource(48, 48)));

        // Blowing a 48px drawing up to 128 would only add blur and bytes.
        expect($result->getImageWidth())->toBe(48);
    });

    it('returns null for something that is not an image', function (): void {
        expect(resolve(ThumbnailService::class)->normalizeEmoji('not an image'))->toBeNull();
    });
});
