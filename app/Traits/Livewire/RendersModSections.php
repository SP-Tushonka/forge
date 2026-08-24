<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Models\Mod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Shared plumbing for the homepage's cached, ordered mod sections: the section sizing, the id-list normalisation, and
 * the hydrate-then-reorder pass that turns a cached id snapshot back into live models.
 *
 * @phpstan-ignore trait.unused
 */
trait RendersModSections
{
    /**
     * The number of mods or comments displayed in each homepage section.
     */
    private const int SECTION_SIZE = 6;

    /**
     * The number of ids cached per ordered section; the extras backfill items that become hidden during the cache TTL.
     */
    private const int SECTION_ID_BUFFER = 12;

    /**
     * If the current user can view disabled mods.
     */
    protected function viewDisabled(): bool
    {
        return auth()->user()?->isModOrAdmin() ?? false;
    }

    /**
     * Convert a plucked id column to a list of integers.
     *
     * @param  SupportCollection<array-key, mixed>  $ids
     * @return list<int>
     */
    protected function toIdList(SupportCollection $ids): array
    {
        $list = [];

        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $list[] = (int) $id;
            }
        }

        return $list;
    }

    /**
     * Load the given mods with the relationships the mod cards render, keyed by id.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Mod>
     */
    protected function hydrateMods(array $ids, bool $viewDisabled = false): Collection
    {
        if ($ids === []) {
            return new Collection();
        }

        return Mod::query()
            ->whereIn('mods.id', array_values(array_unique($ids)))
            ->unless($viewDisabled, fn (Builder $query): Builder => $query->where('mods.disabled', false))
            ->with([
                'latestVersion',
                'latestVersion.latestSptVersion',
                'latestUpdatedVersion',
                'latestUpdatedVersion.latestSptVersion',
                'owner:id,name',
                'additionalAuthors:id,name',
                'license:id,name,link',
            ])
            ->get()
            ->keyBy('id');
    }

    /**
     * Build a section collection from the hydrated mods in the given id order, skipping ids that no longer resolve.
     *
     * @param  Collection<int, Mod>  $mods
     * @param  list<int>  $ids
     * @return Collection<int, Mod>
     */
    protected function pickMods(Collection $mods, array $ids): Collection
    {
        $picked = new Collection();

        foreach ($ids as $id) {
            $mod = $mods->get($id);

            if ($mod !== null) {
                $picked->push($mod);
            }
        }

        return $picked->take(self::SECTION_SIZE);
    }
}
