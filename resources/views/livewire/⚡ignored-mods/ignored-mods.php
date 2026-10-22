<?php

declare(strict_types=1);

use App\Models\IgnoredMod;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function stopIgnoring(int $modId): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $user->unignoreMod($modId);

        Flux::toast(heading: __('No longer ignored'), text: __('The mod is visible across the site again.'), variant: 'success');
    }

    /**
     * @return LengthAwarePaginator<int, IgnoredMod>
     */
    #[Computed]
    public function ignoredMods(): LengthAwarePaginator
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return new LengthAwarePaginator([], 0, 20);
        }

        // An ignored mod that has since been unpublished or disabled still needs a row so it can be removed.
        return $user->ignoredMods()
            ->with(['mod' => function (Relation $relation): void {
                $relation->getQuery()->withoutGlobalScopes();
            }])
            ->latest()
            ->paginate(20, pageName: 'ignoredModsPage');
    }
};
