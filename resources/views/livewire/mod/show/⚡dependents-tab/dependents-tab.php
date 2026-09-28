<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Support\ModStats\DownstreamModsQuery;
use App\Traits\Livewire\AuthorizesModTab;
use App\Traits\Livewire\ModeratesMod;
use App\Traits\Livewire\ProvidesReactionSummary;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

new #[Lazy] class extends Component
{
    use AuthorizesModTab;
    use ModeratesMod;
    use ProvidesReactionSummary;
    use WithPagination;

    public function mount(int $modId): void
    {
        $this->modId = $modId;
        $this->authorizeModTab();
    }

    /**
     * @return list<int>
     */
    protected function reactableIds(): array
    {
        $ids = [];

        foreach ($this->dependents->items() as $mod) {
            $ids[] = $mod->id;
        }

        return $ids;
    }

    /**
     * @return LengthAwarePaginator<int, Mod>
     */
    #[Computed]
    public function dependents(): LengthAwarePaginator
    {
        return Mod::query()
            ->whereIn('id', resolve(DownstreamModsQuery::class)->dependentModIds($this->mod))
            ->with(['owner:id,name', 'additionalAuthors:id,name', 'latestVersion', 'latestVersion.latestSptVersion'])
            ->orderByDesc('downloads')
            ->orderBy('id')
            ->paginate(perPage: 10, pageName: 'dependentPage')
            ->fragment('dependents');
    }
};
