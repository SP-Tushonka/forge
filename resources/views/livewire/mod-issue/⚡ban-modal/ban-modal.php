<?php

declare(strict_types=1);

use App\Actions\ModIssues\BanFromModIssues;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\Scopes\PublishedScope;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $modId;

    #[Locked]
    public ?int $targetUserId = null;

    public string $reason = '';

    public string $duration = 'permanent';

    public bool $showBanModal = false;

    public function mount(int $modId): void
    {
        $this->modId = $modId;
    }

    #[Computed]
    public function mod(): Mod
    {
        return Mod::query()->withoutGlobalScope(PublishedScope::class)->findOrFail($this->modId);
    }

    #[Computed]
    public function target(): ?User
    {
        return $this->targetUserId !== null ? User::query()->find($this->targetUserId) : null;
    }

    /**
     * Opened from the manage panel's "Ban reporter" and from the comment menu's "Ban from issues".
     */
    #[On('ban-from-issues')]
    public function prepare(int $userId): void
    {
        $target = User::query()->findOrFail($userId);

        $this->authorize('ban', [ModIssue::class, $this->mod, $target]);

        $this->targetUserId = $target->id;
        $this->reset('reason', 'duration');
        unset($this->target);

        $this->showBanModal = true;
    }

    public function ban(): void
    {
        $target = $this->target;

        abort_unless($target instanceof User, 404);

        $this->authorize('ban', [ModIssue::class, $this->mod, $target]);

        $this->validate([
            'reason' => ['nullable', 'string', 'max:500'],
            'duration' => ['required', Rule::in(['7', '30', 'permanent'])],
        ]);

        /** @var User $actor */
        $actor = Auth::user();
        $reason = mb_trim($this->reason);

        resolve(BanFromModIssues::class)->execute(
            $this->mod,
            $target,
            $actor,
            $reason === '' ? null : $reason,
            $this->duration === 'permanent' ? null : now()->addDays((int) $this->duration),
        );

        $this->showBanModal = false;
        Flux::toast(text: __(':name can no longer take part in this mod\'s issues.', ['name' => $target->name]), variant: 'success');

        $this->dispatch('mod-issue-updated');
    }
};
