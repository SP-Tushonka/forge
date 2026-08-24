<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Support\Api\V0\PublicViewpoint;
use App\Support\HomepageSectionCache;
use App\Traits\Livewire\ModeratesMod;
use App\Traits\Livewire\RendersModSections;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    use ModeratesMod;
    use RendersModSections;

    /**
     * The selectable leaderboard windows.
     *
     * @var list<string>
     */
    private const array WINDOWS = ['7d', '30d', 'all'];

    /**
     * The selected leaderboard window.
     */
    #[Url(as: 'endorsed', except: '7d')]
    public string $window = '7d';

    public function mount(): void
    {
        $this->updatedWindow($this->window);
    }

    /**
     * Reject an unknown window arriving from the query string or a client-side property update.
     */
    public function updatedWindow(string $value): void
    {
        if (! in_array($value, self::WINDOWS, true)) {
            $this->window = '7d';
        }
    }

    /**
     * Render the component.
     *
     * @return array{mods: Collection<int, Mod>, endorsementCounts: array<int, int>}
     */
    public function with(): array
    {
        $counts = $this->endorsementCounts();
        $ids = array_keys($counts);

        return [
            'mods' => $this->pickMods($this->hydrateMods($ids, $this->viewDisabled()), $ids),
            'endorsementCounts' => $counts,
        ];
    }

    /**
     * The ordered mod id => endorsement count map behind the selected window. The counts are cached alongside the
     * ordering they produced, so the figure on a card can never contradict the position the card sits in.
     *
     * @return array<int, int>
     */
    protected function endorsementCounts(): array
    {
        if ($this->viewDisabled()) {
            return $this->countsQuery(true);
        }

        return HomepageSectionCache::remember(
            $this->section(),
            fn (): array => PublicViewpoint::run(fn (): array => $this->countsQuery(false)),
        );
    }

    /**
     * The cache section backing the selected window.
     */
    protected function section(): string
    {
        return match ($this->window) {
            '30d' => HomepageSectionCache::ENDORSED_MONTH,
            'all' => HomepageSectionCache::ENDORSED_ALL,
            default => HomepageSectionCache::ENDORSED_WEEK,
        };
    }

    /**
     * Read the leaderboard for the selected window as an ordered mod id => count map.
     *
     * @return array<int, int>
     */
    protected function countsQuery(bool $viewDisabled): array
    {
        $pairs = match ($this->window) {
            'all' => $this->allTimeQuery($viewDisabled)->pluck('mods.endorsements_count', 'mods.id'),
            '30d' => $this->intervalQuery(30, $viewDisabled)->pluck('endorsement_counts.endorsements_in_window', 'mods.id'),
            default => $this->intervalQuery(7, $viewDisabled)->pluck('endorsement_counts.endorsements_in_window', 'mods.id'),
        };

        $counts = [];

        foreach ($pairs as $id => $count) {
            if (is_numeric($id) && is_numeric($count)) {
                $counts[(int) $id] = (int) $count;
            }
        }

        return $counts;
    }

    /**
     * The all-time leaderboard, read straight off the denormalised counter.
     *
     * @return Builder<Mod>
     */
    protected function allTimeQuery(bool $viewDisabled): Builder
    {
        return $this->visibleMods(Mod::query()->where('mods.endorsements_count', '>', 0), $viewDisabled)
            ->orderByDesc('mods.endorsements_count')
            ->orderByDesc('mods.id')
            ->limit(self::SECTION_ID_BUFFER);
    }

    /**
     * An interval leaderboard: aggregate the active endorsements anchored inside the window, then join the mods onto
     * that result rather than counting the pivot across the whole mods table.
     *
     * @return Builder<Mod>
     */
    protected function intervalQuery(int $days, bool $viewDisabled): Builder
    {
        $counts = DB::table('mod_endorsements')
            ->selectRaw('mod_id, COUNT(*) AS endorsements_in_window')
            ->whereNull('revoked_at')
            ->where('endorsed_at', '>=', now()->subDays($days))
            ->groupBy('mod_id');

        $query = Mod::query()
            ->joinSub($counts, 'endorsement_counts', 'endorsement_counts.mod_id', '=', 'mods.id')
            ->select('mods.*')
            ->addSelect('endorsement_counts.endorsements_in_window');

        return $this->visibleMods($query, $viewDisabled)
            ->orderByDesc('endorsement_counts.endorsements_in_window')
            ->orderByDesc('mods.id')
            ->limit(self::SECTION_ID_BUFFER);
    }

    /**
     * Apply the same visibility rules the homepage's featured section uses.
     *
     * @param  Builder<Mod>  $query
     * @return Builder<Mod>
     */
    protected function visibleMods(Builder $query, bool $viewDisabled): Builder
    {
        return $query
            ->whereHas('versions', function (Builder $versions) use ($viewDisabled): void {
                $versions->where('disabled', false);
                if (! $viewDisabled) {
                    $versions->whereNotNull('published_at');
                }
            })
            ->unless($viewDisabled, fn (Builder $q): Builder => $q->where('mods.disabled', false));
    }
};
