<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Support\ModStats\DownstreamModsQuery;
use App\Traits\Livewire\AuthorizesModTab;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

new #[Lazy] class extends Component
{
    use AuthorizesModTab;
    use WithPagination;

    public function mount(int $modId): void
    {
        $this->modId = $modId;
        $this->authorizeModTab();
    }

    /**
     * @return LengthAwarePaginator<int, Mod>
     */
    #[Computed]
    public function dependents(): LengthAwarePaginator
    {
        return Mod::query()
            ->whereIn('id', resolve(DownstreamModsQuery::class)->dependentModIds($this->mod))
            ->with(['owner.role', 'latestVersion.latestSptVersion'])
            ->orderByDesc('downloads')
            ->orderBy('id')
            ->paginate(perPage: 10, pageName: 'dependentPage')
            ->fragment('dependents');
    }
};
