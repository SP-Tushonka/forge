<?php

declare(strict_types=1);

use App\Enums\AltMatchOutcome;
use App\Models\AltWatch;
use App\Models\AltWatchMatch;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::base')] #[Title('Alt Monitoring - The Forge')] class extends Component
{
    /**
     * Which watches the table lists: "active" or "all".
     */
    #[Url]
    public string $filter = 'active';

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');
    }

    /**
     * @return array{active: int, unreviewed: int, recent: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'active' => AltWatch::query()->active()->count(),
            'unreviewed' => AltWatchMatch::query()->unreviewed()->count(),
            'recent' => AltWatchMatch::query()->where('baseline', false)->where('first_matched_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * @return Collection<int, AltWatchMatch>
     */
    #[Computed]
    public function unreviewedMatches(): Collection
    {
        return AltWatchMatch::query()
            ->unreviewed()
            ->with(['user', 'watch.indicators', 'watch.watchedUser'])
            ->latest('first_matched_at')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, AltWatch>
     */
    #[Computed]
    public function watches(): Collection
    {
        return AltWatch::query()
            ->when($this->filter !== 'all', fn (Builder $query): Builder => $query->active())
            ->with(['watchedUser', 'creator'])
            ->withCount(['indicators', 'matches'])
            ->latest()
            ->get();
    }

    public function review(int $matchId, string $outcome): void
    {
        $reviewer = auth()->user();
        abort_unless($reviewer instanceof User && $reviewer->isAdmin(), 403);

        $match = AltWatchMatch::query()->find($matchId);
        $result = AltMatchOutcome::tryFrom($outcome);

        if (! $match instanceof AltWatchMatch || ! $result instanceof AltMatchOutcome) {
            return;
        }

        $match->review($result, $reviewer);

        unset($this->stats, $this->unreviewedMatches);

        Flux::toast(text: $result === AltMatchOutcome::Confirmed ? 'Marked as a confirmed alt.' : 'Match dismissed.', variant: 'success');
    }
};
