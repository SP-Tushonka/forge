<?php

declare(strict_types=1);

use App\Actions\ModIssues\LiftModIssueBan;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModIssueBan;
use App\Models\Scopes\PublishedScope;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public int $modId;

    public function mount(int $modId): void
    {
        $this->modId = $modId;

        $this->authorize('manageBans', [ModIssue::class, $this->mod]);
    }

    #[Computed]
    public function mod(): Mod
    {
        return Mod::query()->withoutGlobalScope(PublishedScope::class)->findOrFail($this->modId);
    }

    /**
     * Expired bans keep their row, so a later ban reuses it, but they are not shown.
     *
     * @return Collection<int, ModIssueBan>
     */
    #[Computed]
    public function bans(): Collection
    {
        return $this->mod->issueBans()->active()->with(['user', 'bannedBy'])->latest()->get();
    }

    public function unban(int $banId): void
    {
        $this->authorize('manageBans', [ModIssue::class, $this->mod]);

        resolve(LiftModIssueBan::class)->execute($this->mod->issueBans()->findOrFail($banId));

        unset($this->bans);

        Flux::toast(text: __('Ban lifted.'), variant: 'success');
    }
};
