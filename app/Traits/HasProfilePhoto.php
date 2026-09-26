<?php

declare(strict_types=1);

namespace App\Traits;

use App\Http\Controllers\DefaultAvatarController;
use App\Jobs\NormalizeUserAvatar;
use App\Services\ThumbnailService;
use App\Support\DataTransferObjects\ImageCropRect;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait HasProfilePhoto
{
    /**
     * Update the user's profile photo, remove the previous photo and its variants, and queue normalization of the
     * raw upload (square crop across every frame, WebP re-encode, and variant generation).
     */
    public function updateProfilePhoto(
        UploadedFile $uploadedFile,
        ?ImageCropRect $cropRect = null,
        string $storagePath = 'profile-photos',
    ): void {
        $previousPath = $this->profile_photo_path;
        $previousVariants = $this->profile_photo_variants;

        $rawPath = $uploadedFile->storePublicly($storagePath, ['disk' => $this->profilePhotoDisk()]);
        if ($rawPath === false) {
            return;
        }

        $this->forceFill([
            'profile_photo_path' => $rawPath,
            'profile_photo_variants' => null,
        ])->save();

        if ($previousPath) {
            Storage::disk($this->profilePhotoDisk())->delete($previousPath);
        }

        resolve(ThumbnailService::class)->deleteVariants($this->profilePhotoDisk(), $previousVariants);

        dispatch(new NormalizeUserAvatar($this, $rawPath, $cropRect));
    }

    /**
     * Delete the user's profile photo and its variants.
     */
    public function deleteProfilePhoto(): void
    {
        if (is_null($this->profile_photo_path)) {
            return;
        }

        Storage::disk($this->profilePhotoDisk())->delete($this->profile_photo_path);
        resolve(ThumbnailService::class)->deleteVariants($this->profilePhotoDisk(), $this->profile_photo_variants);

        $this->forceFill([
            'profile_photo_path' => null,
            'profile_photo_variants' => null,
        ])->save();
    }

    /**
     * Get the disk that profile photos should be stored on.
     */
    protected function profilePhotoDisk(): string
    {
        return config()->string('filesystems.asset_upload', 'public');
    }

    /**
     * Get the profile photo URL for the user, preferring the largest resized variant.
     *
     * @return Attribute<string, never>
     */
    protected function profilePhotoUrl(): Attribute
    {
        /** @var Attribute<string, never> $attribute */
        $attribute = new Attribute(
            get: function (): string {
                $variants = $this->profile_photo_variants ?? [];
                if ($variants !== []) {
                    return Storage::disk($this->profilePhotoDisk())->url($variants[max(array_keys($variants))]);
                }

                return $this->profile_photo_path
                    ? Storage::disk($this->profilePhotoDisk())->url($this->profile_photo_path)
                    : $this->defaultProfilePhotoUrl();
            }
        );

        return $attribute;
    }

    /**
     * Build the srcset attribute value for the user's profile photo variants.
     *
     * @return Attribute<string, never>
     */
    protected function profilePhotoSrcset(): Attribute
    {
        /** @var Attribute<string, never> $attribute */
        $attribute = new Attribute(
            get: fn (): string => collect($this->profile_photo_variants ?? [])
                ->map(fn (string $path, int|string $width): string => sprintf('%s %dw', Storage::disk($this->profilePhotoDisk())->url($path), $width))
                ->implode(', ')
        );

        return $attribute;
    }

    /**
     * Get the default profile photo URL if no profile photo has been uploaded: the initials of the first two words of
     * the name. Only letters and digits are kept, since route() leaves characters like "?" and "/" unencoded. Each
     * initial is filtered again after uppercasing, which can expand a letter ("ß" to "SS") or add a combining mark.
     */
    protected function defaultProfilePhotoUrl(): string
    {
        $firstLetter = fn (string $text): string => mb_substr((string) preg_replace('/[^\p{L}\p{N}]/u', '', $text), 0, 1);

        $initials = collect(preg_split('/\s+/u', $this->name ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $word): string => $firstLetter(mb_strtoupper($firstLetter($word))))
            ->filter()
            ->take(2)
            ->join('');

        return route('avatar.default', ['initials' => $initials === '' ? DefaultAvatarController::BLANK : $initials]);
    }
}
