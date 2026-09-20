<?php

declare(strict_types=1);

use App\Actions\ModIssues\ChangeModIssueStatus;
use App\Actions\ModIssues\DeleteModIssue;
use App\Actions\ModIssues\RestoreModIssue;
use App\Actions\ModIssues\SetModIssueLock;
use App\Actions\ModIssues\UpdateModIssueDetails;
use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Models\ModIssue;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $issueId;

    public string $status = '';

    public ?int $duplicateOfId = null;

    public string $type = '';

    public string $fixedVersion = '';

    public function mount(int $issueId): void
    {
        $this->issueId = $issueId;

        abort_unless(Gate::any(['manage', 'restore'], $this->issue), 403);

        $this->fillFromIssue();
    }

    #[Computed]
    public function issue(): ModIssue
    {
        return ModIssue::withTrashed()->with('mod')->findOrFail($this->issueId);
    }

    /**
     * The types the mod accepts, plus whatever this issue already is, so an issue never loses its own type after
     * the owner switches that type off.
     *
     * @return list<ModIssueType>
     */
    #[Computed]
    public function typeOptions(): array
    {
        $types = $this->issue->mod->enabledIssueTypes();

        return in_array($this->issue->type, $types, true) ? $types : [...$types, $this->issue->type];
    }

    /**
     * @return Collection<int, ModIssue>
     */
    #[Computed]
    public function duplicateCandidates(): Collection
    {
        return ModIssue::query()
            ->where('mod_id', $this->issue->mod_id)
            ->whereKeyNot($this->issueId)
            ->orderByDesc('number')
            ->get(['id', 'mod_id', 'number', 'title']);
    }

    public function saveStatus(): void
    {
        $this->authorize('manage', $this->issue);
        $this->validate(['status' => ['required', Rule::enum(ModIssueStatus::class)]]);

        $duplicateOf = $this->duplicateOfId !== null ? ModIssue::query()->find($this->duplicateOfId) : null;

        resolve(ChangeModIssueStatus::class)->execute($this->issue, ModIssueStatus::from($this->status), $this->actor(), $duplicateOf);

        $this->changed(__('Status updated.'));
    }

    public function saveDetails(): void
    {
        $this->authorize('manage', $this->issue);
        $this->validate(['type' => ['required', Rule::enum(ModIssueType::class)]]);

        resolve(UpdateModIssueDetails::class)->execute($this->issue, $this->actor(), ModIssueType::from($this->type), $this->fixedVersion);

        $this->changed(__('Details saved.'));
    }

    public function toggleLock(): void
    {
        $this->authorize('manage', $this->issue);

        $locking = ! $this->issue->isLocked();

        resolve(SetModIssueLock::class)->execute($this->issue, $locking, $this->actor());

        $this->changed($locking ? __('Issue locked.') : __('Issue unlocked.'));
    }

    public function deleteIssue(): void
    {
        $this->authorize('delete', $this->issue);

        resolve(DeleteModIssue::class)->execute($this->issue, $this->actor());

        $this->changed(__('Issue deleted. Staff can restore it.'));
    }

    public function restoreIssue(): void
    {
        $this->authorize('restore', $this->issue);

        resolve(RestoreModIssue::class)->execute($this->issue);

        $this->changed(__('Issue restored.'));
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function changed(string $message): void
    {
        unset($this->issue, $this->duplicateCandidates);

        $this->fillFromIssue();
        $this->dispatch('mod-issue-updated');

        Flux::toast(text: $message, variant: 'success');
    }

    private function fillFromIssue(): void
    {
        $this->status = $this->issue->status->value;
        $this->duplicateOfId = $this->issue->duplicate_of_id;
        $this->type = $this->issue->type->value;
        $this->fixedVersion = $this->issue->fixed_version ?? '';
    }
};
