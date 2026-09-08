<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Jobs\GenerateThumbnailVariants;
use App\Models\Mod;
use App\Models\SourceCodeLink;
use App\Models\SptVersion;
use App\Rules\NoBlockRelationship;
use App\Services\ThumbnailService;
use App\Support\VersionMatcher;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The mod detail field set, shared by the public mod edit page and the staff Mod tool.
 *
 * Properties are declared here rather than in a Livewire form object on purpose: a form object
 * namespaces every binding and error key under "form.", which would rename all of these fields
 * and invalidate the existing test suite.
 *
 * @phpstan-ignore trait.unused
 */
trait EditsMod
{
    public ?UploadedFile $thumbnail = null;

    public string $name = '';

    public string $guid = '';

    public string $teaser = '';

    public string $description = '';

    public string $license = '';

    public string $category = '';

    /** @var array<int, array{key: string, url: string, label: string|null}> */
    public array $sourceCodeLinks = [];

    public ?string $publishedAtDate = null;

    public ?string $publishedAtTime = null;

    public bool $containsAiContent = false;

    public bool $containsAiContentLocked = false;

    public string $customAiDisclosure = '';

    public bool $containsAds = false;

    public bool $commentsDisabled = false;

    /** @var array<int> */
    public array $authorIds = [];

    public bool $disableProfileBindingNotice = false;

    public bool $cheatNotice = false;

    public bool $addonsDisabled = false;

    public bool $listsDisabled = false;

    /**
     * Add a new source code link input.
     */
    public function addSourceCodeLink(): void
    {
        if (count($this->sourceCodeLinks) < 4) {
            $this->sourceCodeLinks[] = ['key' => uniqid('link-'), 'url' => '', 'label' => ''];
        }
    }

    /**
     * Remove a source code link input.
     */
    public function removeSourceCodeLink(int $index): void
    {
        if (count($this->sourceCodeLinks) > 1) {
            array_splice($this->sourceCodeLinks, $index, 1);
        }
    }

    /**
     * Remove the pending upload from the form. Does not affect the stored thumbnail.
     */
    public function removeThumbnail(): void
    {
        $this->thumbnail = null;
        $this->resetErrorBag('thumbnail');
    }

    /**
     * Hydrate every field from the mod.
     */
    protected function setModFields(Mod $mod): void
    {
        $this->name = $mod->name;
        $this->guid = $mod->guid ?? '';
        $this->teaser = $mod->teaser;
        $this->description = $mod->description;
        $this->license = (string) $mod->license_id;
        $this->category = (string) ($mod->category_id ?? '');

        $this->sourceCodeLinks = $mod->sourceCodeLinks
            ->values()
            ->map(
                fn (SourceCodeLink $link, int $index): array => [
                    'key' => 'link-'.$index,
                    'url' => $link->url,
                    'label' => $link->label,
                ],
            )
            ->all();

        // Ensure at least one empty link input if no links exist
        if ($this->sourceCodeLinks === []) {
            $this->sourceCodeLinks[] = ['key' => 'link-0', 'url' => '', 'label' => ''];
        }

        if ($mod->published_at) {
            $publishedAtLocal = Date::parse($mod->published_at)
                ->setTimezone(auth()->user()->timezone ?? 'UTC');
            $this->publishedAtDate = $publishedAtLocal->format('Y-m-d');
            $this->publishedAtTime = $publishedAtLocal->format('H:i');
        }

        $this->containsAiContent = (bool) $mod->contains_ai_content;
        $this->containsAiContentLocked = (bool) $mod->contains_ai_content_locked;
        $this->customAiDisclosure = $mod->custom_ai_disclosure ?? '';
        $this->containsAds = (bool) $mod->contains_ads;
        $this->commentsDisabled = (bool) $mod->comments_disabled;
        $this->disableProfileBindingNotice = (bool) $mod->profile_binding_notice_disabled;
        $this->cheatNotice = (bool) $mod->cheat_notice;
        $this->addonsDisabled = (bool) $mod->addons_disabled;
        $this->listsDisabled = (bool) $mod->lists_disabled;

        /** @var array<int> $authorIds */
        $authorIds = $mod->additionalAuthors->pluck('id')->toArray();
        $this->authorIds = $authorIds;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    protected function modRules(Mod $mod): array
    {
        // GUID is required if any existing mod version targets SPT >= 4.0.0
        $guidRules = [
            $this->guidRequiredFor($mod) ? 'required' : 'nullable',
            'string',
            'max:255',
            'regex:'.Mod::GUID_REGEX,
            'unique:mods,guid,'.$mod->id,
        ];

        return [
            'thumbnail' => 'nullable|mimes:jpg,jpeg,png,webp,gif,avif|max:2048',
            'name' => 'required|string|max:75',
            'guid' => $guidRules,
            'teaser' => 'required|string|max:255',
            'description' => 'required|string',
            'license' => 'required|exists:licenses,id',
            'category' => 'required|exists:mod_categories,id',
            'sourceCodeLinks' => 'required|array|min:1|max:4',
            'sourceCodeLinks.*.url' => 'required|url|starts_with:https://,http://',
            'sourceCodeLinks.*.label' => 'nullable|string|max:50',
            'publishedAtDate' => 'nullable|date',
            'publishedAtTime' => 'nullable|date_format:H:i',
            'containsAiContent' => 'boolean',
            'containsAiContentLocked' => 'boolean',
            'customAiDisclosure' => 'required_if:containsAiContent,true|string|max:1000',
            'containsAds' => 'boolean',
            'commentsDisabled' => 'boolean',
            'authorIds' => 'array|max:10',
            'authorIds.*' => ['exists:users,id', 'distinct', new NoBlockRelationship($mod->owner, $this->existingAuthorIds($mod))],
            'disableProfileBindingNotice' => 'boolean',
            'cheatNotice' => 'boolean',
            'addonsDisabled' => 'boolean',
            'listsDisabled' => 'boolean',
        ];
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    protected function modMessages(): array
    {
        return [
            'sourceCodeLinks.required' => 'At least one source code link is required.',
            'sourceCodeLinks.min' => 'At least one source code link is required.',
            'sourceCodeLinks.max' => 'You can add a maximum of 4 source code links.',
            'sourceCodeLinks.*.url.required' => 'Please enter a valid URL for the source code.',
            'sourceCodeLinks.*.url.url' => 'Please enter a valid URL (e.g., https://github.com/username/repo).',
            'sourceCodeLinks.*.url.starts_with' => 'The URL must start with https:// or http://',
            'sourceCodeLinks.*.label.max' => 'The label must not exceed 50 characters.',
            'customAiDisclosure.required_if' => 'Please describe how AI was used when your mod contains AI content.',
        ];
    }

    /**
     * Get the IDs of the currently attached additional authors.
     *
     * @return list<int>
     */
    protected function existingAuthorIds(Mod $mod): array
    {
        /** @var list<int> */
        return $mod->additionalAuthors()->pluck('users.id')->all();
    }

    /**
     * Whether any existing version targets SPT 4.0.0+, which makes the GUID required.
     */
    protected function guidRequiredFor(Mod $mod): bool
    {
        foreach ($mod->versions as $version) {
            if ($this->constraintSatisfiesSpt4OrAbove($version->spt_version_constraint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Combine the date and time inputs into a single UTC value.
     */
    protected function publishedAtValue(string $timezone): ?CarbonImmutable
    {
        if ($this->publishedAtDate === null || $this->publishedAtDate === '') {
            return null;
        }

        $dateTimeString = $this->publishedAtDate.' '.($this->publishedAtTime ?? '00:00');

        return Date::parse($dateTimeString, $timezone)->setTimezone('UTC')->second(0);
    }

    /**
     * Whether the licence has to be proven again on this save.
     */
    protected function requiresLicenseReverification(Mod $mod, ?CarbonImmutable $publishedAt): bool
    {
        if ($publishedAt === null || ! $this->customLicenseSelected) {
            return false;
        }

        if ($mod->published_at === null || $mod->license_id !== (int) $this->license) {
            return true;
        }

        $stored = $mod->sourceCodeLinks()->pluck('url')->all();
        $submitted = $this->sourceCodeUrls();
        sort($stored);
        sort($submitted);

        return $stored !== $submitted;
    }

    /**
     * The non empty source code links entered on the form.
     *
     * @return list<string>
     */
    protected function sourceCodeUrls(): array
    {
        $urls = array_map(static fn (array $link): string => $link['url'], $this->sourceCodeLinks);

        return array_values(array_filter($urls, static fn (string $url): bool => mb_trim($url) !== ''));
    }

    /**
     * Write every field, the thumbnail, the source code links and the authors.
     */
    protected function applyModFields(Mod $mod, bool $canLockAiContent, string $timezone): Mod
    {
        $mod->name = $this->name;
        // Slug from the stored (censored) name so a censored word never leaks into the URL
        $mod->slug = Str::slug($mod->name);
        $mod->guid = $this->guid;
        $mod->teaser = $this->teaser;
        $mod->description = $this->description;
        $mod->license_id = (int) $this->license;
        $mod->category_id = (int) $this->category;

        if ($canLockAiContent) {
            $mod->contains_ai_content_locked = $this->containsAiContentLocked;
            $mod->contains_ai_content = $this->containsAiContentLocked ? true : $this->containsAiContent;
        } elseif (! $mod->contains_ai_content_locked) {
            $mod->contains_ai_content = $this->containsAiContent;
        }

        $mod->custom_ai_disclosure = $mod->contains_ai_content && $this->customAiDisclosure !== ''
            ? $this->customAiDisclosure
            : null;

        $mod->contains_ads = $this->containsAds;
        $mod->comments_disabled = $this->commentsDisabled;
        $mod->profile_binding_notice_disabled = $this->disableProfileBindingNotice;
        $mod->cheat_notice = $this->cheatNotice;
        $mod->addons_disabled = $this->addonsDisabled;
        $mod->lists_disabled = $this->listsDisabled;
        $mod->published_at = $this->publishedAtValue($timezone);

        if ($this->thumbnail instanceof UploadedFile) {
            /** @var string $diskName */
            $diskName = config('filesystems.asset_upload', 'public');

            // Delete the old thumbnail file from storage
            if ($mod->thumbnail) {
                Storage::disk($diskName)->delete($mod->thumbnail);
            }

            $thumbnailPath = $this->thumbnail->storePublicly(path: 'mods', options: $diskName);
            if ($thumbnailPath !== false) {
                $mod->thumbnail = $thumbnailPath;
            }

            $fileContents = $this->thumbnail->get();
            if ($fileContents !== false) {
                $mod->thumbnail_hash = md5($fileContents);
            }
        }

        $mod->save();

        // Generate resized thumbnail variants in the background.
        if ($this->thumbnail instanceof UploadedFile) {
            dispatch(new GenerateThumbnailVariants($mod));
        }

        $mod->sourceCodeLinks()->delete();
        foreach ($this->sourceCodeLinks as $link) {
            if (! empty($link['url'])) {
                $mod->sourceCodeLinks()->create([
                    'url' => $link['url'],
                    'label' => $link['label'] ?? '',
                ]);
            }
        }

        // Update authors (sync will add/remove as needed)
        $mod->additionalAuthors()->sync($this->authorIds);

        return $mod;
    }

    /**
     * Delete the stored thumbnail and its generated variants.
     */
    protected function deleteStoredThumbnail(Mod $mod): void
    {
        if (! $mod->thumbnail) {
            return;
        }

        /** @var string $diskName */
        $diskName = config('filesystems.asset_upload', 'public');
        Storage::disk($diskName)->delete($mod->thumbnail);
        resolve(ThumbnailService::class)->deleteVariants($diskName, $mod->thumbnail_variants);

        $mod->thumbnail = '';
        $mod->thumbnail_hash = '';
        $mod->thumbnail_variants = null;
        $mod->save();
    }

    /**
     * Check if a version constraint includes SPT 4.0.0 or above.
     */
    private function constraintSatisfiesSpt4OrAbove(string $constraint): bool
    {
        try {
            // Get all valid SPT versions
            $allSptVersions = SptVersion::allValidVersions();

            // Get versions that match the constraint
            $matchingVersions = VersionMatcher::satisfiedBy($allSptVersions, $constraint);

            // Check if any matching version is >= 4.0.0
            foreach ($matchingVersions as $version) {
                if (VersionMatcher::satisfies($version, '>=4.0.0')) {
                    return true;
                }
            }
        } catch (Exception) {
            // If there's an error parsing the constraint, assume it doesn't require GUID
            return false;
        }

        return false;
    }
}
