<?php

declare(strict_types=1);

use App\Actions\Staff\ClearModOwnership;
use App\Actions\Staff\TransferModOwnership;
use App\Enums\TrackingEventType;
use App\Exceptions\StaffActionException;
use App\Facades\Track;
use App\Models\License;
use App\Models\Mod;
use App\Models\ModCategory;
use App\Models\User;
use App\Traits\Livewire\EditsMod;
use App\Traits\Livewire\ModeratesModVersion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use EditsMod;
    use ModeratesModVersion;
    use WithFileUploads;

    /** Free text search: name, slug, GUID, mod id or owner name. */
    public string $search = '';

    /** The resolved target, kept in the URL so staff can share a link. */
    #[Url]
    public ?int $modId = null;

    /**
     * Mandatory justification for a Details save, stored on the tracking event. Declared here
     * rather than in EditsMod: a staff-only field in the shared trait would leak onto the public
     * edit form.
     */
    public string $reason = '';

    /**
     * Mandatory justification for an ownership change. Kept separate from $reason so each panel
     * has its own visible required field — one shared box left the Ownership panel demanding a
     * value with nowhere to type it.
     */
    public string $ownershipReason = '';

    /** Free text search for the incoming owner. */
    public string $ownerSearch = '';

    /** The user selected to receive the mod. */
    public ?int $newOwnerId = null;

    /** Whether the outgoing owner keeps edit access as an additional author. */
    public bool $keepPreviousAsAuthor = true;

    public function mount(?int $modId = null): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');

        if ($modId !== null) {
            $this->modId = $modId;
        }

        $mod = $this->target;

        if ($mod !== null) {
            $this->setModFields($mod);
        }
    }

    /**
     * Search results. Empty until the staff member types something.
     *
     * @return Collection<int, Mod>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = mb_trim($this->search);

        if ($term === '') {
            return new Collection;
        }

        return Mod::query()
            ->with(['owner:id,name', 'license:id,name', 'category:id,title'])
            ->where(function (Builder $query) use ($term): void {
                $query->whereLike('name', '%'.$term.'%')
                    ->orWhereLike('slug', '%'.$term.'%')
                    ->orWhereLike('guid', '%'.$term.'%')
                    ->orWhereHas('owner', fn (Builder $owner): Builder => $owner->whereLike('name', '%'.$term.'%'));

                // Postgres refuses a non-numeric comparison against bigint columns.
                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }
            })
            ->orderBy('name')
            ->limit(15)
            ->get();
    }

    /**
     * The mod being acted upon, with every relation the summary card and panels read
     * eager-loaded — Model::shouldBeStrict() is active in production.
     */
    #[Computed]
    public function target(): ?Mod
    {
        if ($this->modId === null) {
            return null;
        }

        return Mod::query()
            ->with([
                'owner:id,name',
                'license:id,name',
                'category:id,title',
                'additionalAuthors:id,name',
                'sourceCodeLinks',
                'versions',
            ])
            ->withCount('versions')
            ->find($this->modId);
    }

    public function selectMod(int $modId): void
    {
        $this->modId = $modId;
        $this->search = '';

        unset($this->target);

        $mod = $this->target;

        if ($mod !== null) {
            $this->setModFields($mod);
        }
    }

    public function clearMod(): void
    {
        $this->modId = null;

        unset($this->target);
    }

    /**
     * @return Collection<int, License>
     */
    #[Computed]
    public function licenses(): Collection
    {
        return License::cachedOrdered();
    }

    /**
     * @return Collection<int, ModCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ModCategory::cachedOrdered();
    }

    /**
     * Required by EditsMod::requiresLicenseReverification, which staff saves never call.
     */
    #[Computed]
    public function customLicenseSelected(): bool
    {
        return License::query()->find((int) $this->license)?->isCustom() ?? false;
    }

    #[Computed]
    public function canLockAiContent(): bool
    {
        $mod = $this->target;

        return $mod !== null && (auth()->user()?->can('lockAiContent', $mod) ?? false);
    }

    /**
     * @param  array<int>  $ids
     */
    #[On('updateAuthorIds')]
    public function updateAuthorIds(array $ids): void
    {
        $this->authorIds = $ids;
    }

    public function saveDetails(): void
    {
        $mod = $this->target;
        $staff = auth()->user();

        if ($mod === null || ! $staff instanceof User) {
            return;
        }

        $this->authorize('update', $mod);

        $this->validate(
            [...$this->modRules($mod), 'reason' => 'required|string|max:1000'],
            $this->modMessages(),
        );

        // Custom-licence reverification is deliberately skipped. It proves the *acting* user
        // controls the linked repository, which is meaningless when staff edit someone else's mod.
        $this->applyModFields($mod, $this->canLockAiContent, $staff->timezone ?? 'UTC');

        Track::eventSync(
            TrackingEventType::MOD_EDIT,
            $mod,
            isModerationAction: true,
            reason: $this->reason,
        );

        unset($this->target);

        Flux::toast(heading: __('Mod Updated'), text: __('The mod has been updated.'), variant: 'success');
    }

    /**
     * Candidate owners. Empty until the staff member types something.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function ownerResults(): Collection
    {
        $term = mb_trim($this->ownerSearch);

        if ($term === '') {
            return new Collection;
        }

        return User::query()
            ->where(function (Builder $query) use ($term): void {
                $query->whereLike('name', '%'.$term.'%')
                    ->orWhereLike('email', '%'.$term.'%');

                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }
            })
            ->orderBy('name')
            ->limit(15)
            ->get();
    }

    public function selectNewOwner(int $userId): void
    {
        $this->newOwnerId = $userId;
        $this->ownerSearch = '';
    }

    public function transferOwnership(): void
    {
        $mod = $this->target;
        $staff = auth()->user();

        if ($mod === null || ! $staff instanceof User) {
            return;
        }

        $this->validate([
            'ownershipReason' => 'required|string|max:1000',
            'newOwnerId' => 'required|integer|exists:users,id',
        ]);

        $newOwner = User::query()->find($this->newOwnerId);

        if (! $newOwner instanceof User) {
            $this->addError('action', __('That user no longer exists.'));

            return;
        }

        try {
            resolve(TransferModOwnership::class)
                ->execute($staff, $mod, $newOwner, $this->keepPreviousAsAuthor, $this->ownershipReason);
        } catch (StaffActionException $exception) {
            $this->addError('action', $exception->getMessage());

            return;
        }

        $this->newOwnerId = null;
        $this->ownershipReason = '';

        unset($this->target);

        Flux::toast(heading: __('Ownership Transferred'), text: __('The mod has a new owner.'), variant: 'success');
    }

    public function clearOwnership(): void
    {
        $mod = $this->target;
        $staff = auth()->user();

        if ($mod === null || ! $staff instanceof User) {
            return;
        }

        $this->validate(['ownershipReason' => 'required|string|max:1000']);

        try {
            resolve(ClearModOwnership::class)
                ->execute($staff, $mod, $this->keepPreviousAsAuthor, $this->ownershipReason);
        } catch (StaffActionException $exception) {
            $this->addError('action', $exception->getMessage());

            return;
        }

        $this->ownershipReason = '';

        unset($this->target);

        Flux::toast(heading: __('Ownership Cleared'), text: __('The mod is now unowned and claimable.'), variant: 'success');
    }
};
