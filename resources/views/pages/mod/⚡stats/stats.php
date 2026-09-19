<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\Scopes\PublishedScope;
use App\Services\ModStats\ModStatsService;
use App\Support\DataTransferObjects\StatsRange;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::base')] class extends Component
{
    #[Locked]
    public Mod $mod;

    #[Url]
    public string $days = '30';

    #[Url]
    public string $grain = 'daily';

    public function mount(int $modId, string $slug): void
    {
        $this->mod = Mod::query()->withoutGlobalScope(PublishedScope::class)->findOrFail($modId);

        if ($this->mod->slug !== $slug) {
            $this->redirectRoute('mod.stats', ['modId' => $this->mod->id, 'slug' => $this->mod->slug]);
        }

        Gate::authorize('viewStats', $this->mod);
    }

    /**
     * Re-authorize on every subsequent request.
     */
    public function hydrate(): void
    {
        Gate::authorize('viewStats', $this->mod);
    }

    public function updatedDays(): void
    {
        if ($this->days === '7') {
            $this->grain = 'daily';
        }
    }

    #[Computed]
    public function range(): StatsRange
    {
        return StatsRange::make($this->days, $this->grain);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function report(): array
    {
        return resolve(ModStatsService::class)->forMod($this->mod, $this->range);
    }
};
