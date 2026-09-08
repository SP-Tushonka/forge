<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Livewire\Concerns\RendersMarkdownPreview;
use App\Models\License;
use App\Models\Mod;
use App\Models\ModCategory;
use App\Services\License\CustomLicenseVerificationService;
use App\Traits\Livewire\EditsMod;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Spatie\Honeypot\Http\Livewire\Concerns\HoneypotData;
use Spatie\Honeypot\Http\Livewire\Concerns\UsesSpamProtection;

new #[Layout('layouts::base')] class extends Component
{
    use EditsMod;
    use RendersMarkdownPreview;
    use UsesSpamProtection;
    use WithFileUploads;

    /**
     * The honeypot data to be validated.
     */
    public HoneypotData $honeypotData;

    /**
     * The mod being edited.
     */
    public Mod $mod;

    /**
     * Mount the component.
     */
    public function mount(int $modId): void
    {
        $this->honeypotData = new HoneypotData();

        $this->mod = Mod::query()
            ->with(['sourceCodeLinks', 'additionalAuthors'])
            ->findOrFail($modId);

        $this->authorize('update', $this->mod);

        $this->setModFields($this->mod);
    }

    /**
     * Update the author IDs from the child component.
     *
     * @param  array<int>  $ids
     */
    #[On('updateAuthorIds')]
    public function updateAuthorIds(array $ids): void
    {
        $this->authorIds = $ids;
    }

    /**
     * Whether the current user can lock or unlock the AI content flag.
     */
    #[Computed]
    public function canLockAiContent(): bool
    {
        return auth()->user()?->can('lockAiContent', $this->mod) ?? false;
    }

    /**
     * Whether the AI content flag is currently locked and the user cannot change it.
     */
    #[Computed]
    public function aiContentLockedForUser(): bool
    {
        return $this->mod->contains_ai_content_locked && ! $this->canLockAiContent;
    }

    /**
     * Check if the selected category shows profile binding notice by default.
     */
    public function shouldShowProfileBindingField(): bool
    {
        if ($this->category === '' || $this->category === '0') {
            return false;
        }

        $category = ModCategory::query()->find($this->category);

        return $category && $category->shows_profile_binding_notice;
    }

    /**
     * Get all licenses ordered by name.
     *
     * @return Collection<int, License>
     */
    #[Computed]
    public function licenses(): Collection
    {
        return License::cachedOrdered();
    }

    /**
     * Get all mod categories ordered by title.
     *
     * @return Collection<int, ModCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ModCategory::cachedOrdered();
    }

    /**
     * Whether the selected license requires the modder to ship their own licence text
     */
    #[Computed]
    public function customLicenseSelected(): bool
    {
        return License::query()->find((int) $this->license)?->isCustom() ?? false;
    }

    /**
     * Check if GUID is required based on existing mod versions with SPT >= 4.0.0.
     */
    #[Computed]
    public function isGuidRequired(): bool
    {
        return $this->guidRequiredFor($this->mod);
    }

    /**
     * Save the mod.
     */
    public function save(CustomLicenseVerificationService $customLicense): void
    {
        $this->authorize('update', $this->mod);

        // Validate the honeypot data.
        $this->protectAgainstSpam();

        // Validate the form.
        $validated = $this->validate();
        if (! $validated) {
            return;
        }

        $timezone = auth()->user()->timezone ?? 'UTC';
        $publishedAtCarbon = $this->publishedAtValue($timezone);

        if ($this->requiresLicenseReverification($this->mod, $publishedAtCarbon)) {
            $currentUser = auth()->user();

            if ($currentUser === null || ! $customLicense->passes($this->sourceCodeUrls(), $currentUser)) {
                $this->addError('license', 'There was an error verifying your LICENSE.md file');

                return;
            }
        }

        $this->applyModFields($this->mod, $this->canLockAiContent, $timezone);

        // A staff member editing content they do not own is a moderation action and belongs in
        // the moderation log. This page has no reason field, so staff edits made here record a
        // null reason; the Staff Tools mod tool is the path that captures one.
        $actor = auth()->user();
        $isModerationAction = $actor && ! $this->mod->isAuthorOrOwner($actor) && $actor->isModOrAdmin();

        Track::eventSync(
            TrackingEventType::MOD_EDIT,
            $this->mod,
            isModerationAction: $isModerationAction,
        );

        Flux::toast(heading: 'Mod Updated', text: 'Your mod has been successfully updated.', variant: 'success');

        $this->redirect($this->mod->detail_url, navigate: true);
    }

    /**
     * Delete the existing thumbnail from the mod.
     */
    public function deleteExistingThumbnail(): void
    {
        $this->authorize('update', $this->mod);

        if (! $this->mod->thumbnail) {
            return;
        }

        $this->deleteStoredThumbnail($this->mod);

        Flux::toast(heading: 'Thumbnail Deleted', text: 'The mod thumbnail has been deleted.', variant: 'success');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return $this->modRules($this->mod);
    }

    /**
     * Get custom validation messages.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->modMessages();
    }
};

