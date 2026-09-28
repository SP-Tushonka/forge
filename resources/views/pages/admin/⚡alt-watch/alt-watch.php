<?php

declare(strict_types=1);

use App\Enums\AltMatchOutcome;
use App\Models\AltWatch;
use App\Models\AltWatchMatch;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::base')] #[Title('Alt Watch - The Forge')] class extends Component
{
    public AltWatch $watch;

    public function mount(AltWatch $watch): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');

        $this->watch = $watch->load(['indicators', 'watchedUser', 'creator', 'ender']);
    }

    /**
     * Matches found after the watch was saved, newest first.
     *
     * @return Collection<int, AltWatchMatch>
     */
    #[Computed]
    public function newMatches(): Collection
    {
        return $this->matches(baseline: false);
    }

    /**
     * Accounts that already matched when the watch was saved or edited.
     *
     * @return Collection<int, AltWatchMatch>
     */
    #[Computed]
    public function baselineMatches(): Collection
    {
        return $this->matches(baseline: true);
    }

    public function review(int $matchId, string $outcome): void
    {
        $reviewer = auth()->user();
        abort_unless($reviewer instanceof User && $reviewer->isAdmin(), 403);

        $match = $this->watch->matches()->find($matchId);
        $result = AltMatchOutcome::tryFrom($outcome);

        if (! $match instanceof AltWatchMatch || ! $result instanceof AltMatchOutcome) {
            return;
        }

        $match->review($result, $reviewer);

        unset($this->newMatches);
    }

    public function endWatch(): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isAdmin(), 403);

        if ($this->watch->ended_at !== null) {
            return;
        }

        $this->watch->end($actor);
        $this->watch->load('ender');

        Flux::toast(text: 'Watch ended. It will be deleted in 30 days.', variant: 'success');
    }

    /**
     * @return Collection<int, AltWatchMatch>
     */
    private function matches(bool $baseline): Collection
    {
        return $this->watch->matches()
            ->where('baseline', $baseline)
            ->with(['user', 'reviewer'])
            ->latest('first_matched_at')
            ->get()
            ->each(fn (AltWatchMatch $match): AltWatchMatch => $match->setRelation('watch', $this->watch));
    }
};
