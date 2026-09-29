<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Support\ModStats\DownstreamModsQuery;
use App\Traits\Livewire\AuthorizesModTab;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Lazy] class extends Component
{
    use AuthorizesModTab;
    use WithPagination;

    private const int PER_PAGE = 10;

    #[Url(as: 'dependentsSpt', except: '')]
    public string $sptVersion = '';

    #[Url(as: 'dependentsSort', except: 'downloads')]
    public string $sort = 'downloads';

    public function mount(int $modId): void
    {
        $this->modId = $modId;
        $this->authorizeModTab();

        if ($this->sptVersion !== '' && ! $this->selectedSptVersion instanceof SptVersion) {
            $this->sptVersion = '';
        }

        if (! array_key_exists($this->sort, $this->sortOptions)) {
            $this->sort = 'downloads';
        }
    }

    public function updatedSptVersion(): void
    {
        $this->resetPage('dependentPage');
    }

    public function updatedSort(): void
    {
        $this->resetPage('dependentPage');
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function sortOptions(): array
    {
        return [
            'downloads' => __('Download count'),
            'updated' => __('Recently updated'),
            'created' => __('Newest'),
            'name' => __('Name (A-Z)'),
            'spt' => __('SPT version'),
        ];
    }

    /**
     * @return array{latest: array<int, int>, bySpt: array<int, array<int, int>>}
     */
    #[Computed]
    public function dependentVersionIds(): array
    {
        return resolve(DownstreamModsQuery::class)->dependentVersionIds($this->mod);
    }

    /**
     * @return EloquentCollection<int, SptVersion>
     */
    #[Computed]
    public function sptVersionOptions(): EloquentCollection
    {
        return SptVersion::query()
            ->whereIn('id', array_keys($this->dependentVersionIds['bySpt']))
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor')
            ->orderByDesc('version_patch')
            ->orderByRaw('CASE WHEN version_labels = ? THEN 0 ELSE 1 END', [''])
            ->orderBy('version_labels')
            ->get();
    }

    #[Computed]
    public function selectedSptVersion(): ?SptVersion
    {
        return $this->sptVersionOptions->firstWhere('version', '===', $this->sptVersion);
    }

    /**
     * The version listed for each dependent, keyed by mod id.
     *
     * @return EloquentCollection<int, ModVersion>
     */
    #[Computed]
    public function shownVersions(): EloquentCollection
    {
        $versionIds = $this->selectedSptVersion instanceof SptVersion
            ? $this->dependentVersionIds['bySpt'][$this->selectedSptVersion->id] ?? []
            : $this->dependentVersionIds['latest'];

        return ModVersion::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $versionIds)
            ->with('latestSptVersion')
            ->get(['id', 'mod_id', 'version', 'created_at'])
            ->keyBy('mod_id');
    }

    /**
     * The SPT badge for each dependent, keyed by mod id: the filtered SPT version, or else the newest one its listed
     * version supports.
     *
     * @return Collection<int, SptVersion|null>
     */
    #[Computed]
    public function badgeSptVersions(): Collection
    {
        return $this->shownVersions
            ->toBase()
            ->map(fn (ModVersion $version): ?SptVersion => $this->selectedSptVersion ?? $version->latestSptVersion);
    }

    /**
     * @return LengthAwarePaginator<int, Mod>
     */
    #[Computed]
    public function dependents(): LengthAwarePaginator
    {
        $sortedIds = $this->sortedDependentIds();
        $currentRaw = $this->getPage('dependentPage');
        $page = is_numeric($currentRaw) ? max(1, (int) $currentRaw) : 1;
        $pageIds = $sortedIds->forPage($page, self::PER_PAGE)->values();

        $mods = Mod::query()
            ->whereIn('id', $pageIds)
            ->with('owner.role')
            ->get()
            ->sortBy(fn (Mod $mod): int => (int) $pageIds->search($mod->id))
            ->values();

        return (new LengthAwarePaginator($mods, $sortedIds->count(), self::PER_PAGE, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'dependentPage',
        ]))->fragment('dependents');
    }

    /**
     * Every sort except "created" and "name" orders by what the row shows: the listed version's release date or the
     * SPT badge. Ties fall back to download count.
     *
     * @return Collection<int, int>
     */
    private function sortedDependentIds(): Collection
    {
        $mods = Mod::query()
            ->whereIn('id', $this->shownVersions->keys())
            ->get(['id', 'name', 'downloads', 'created_at']);

        $byDownloads = [
            fn (Mod $a, Mod $b): int => $b->downloads <=> $a->downloads,
            fn (Mod $a, Mod $b): int => $a->id <=> $b->id,
        ];

        $criteria = match ($this->sort) {
            'updated' => [fn (Mod $a, Mod $b): int => $this->shownVersions->get($b->id)?->created_at <=> $this->shownVersions->get($a->id)?->created_at, ...$byDownloads],
            'created' => [fn (Mod $a, Mod $b): int => $b->created_at <=> $a->created_at, ...$byDownloads],
            'name' => [fn (Mod $a, Mod $b): int => strnatcasecmp($a->name, $b->name), ...$byDownloads],
            'spt' => [fn (Mod $a, Mod $b): int => $this->sptRank($b) <=> $this->sptRank($a), ...$byDownloads],
            default => $byDownloads,
        };

        return $mods->sortBy($criteria)->map(fn (Mod $mod): int => $mod->id)->values()->toBase();
    }

    /**
     * @return array{int, int, int, int}
     */
    private function sptRank(Mod $mod): array
    {
        $spt = $this->badgeSptVersions->get($mod->id);

        return $spt === null
            ? [-1, -1, -1, -1]
            : [$spt->version_major, $spt->version_minor, $spt->version_patch, $spt->version_labels === '' ? 1 : 0];
    }
};
