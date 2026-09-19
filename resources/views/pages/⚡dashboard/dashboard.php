<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\ModStats\ModStatsService;
use App\Support\DataTransferObjects\StatsRange;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::base')] class extends Component
{
    /**
     * @var list<string>
     */
    private const array SORTABLE = ['name', 'downloads', 'views'];

    #[Url]
    public string $days = '30';

    #[Url]
    public string $grain = 'daily';

    #[Url]
    public string $sortBy = 'downloads';

    #[Url]
    public string $sortDirection = 'desc';

    public function updatedDays(): void
    {
        if ($this->days === '7') {
            $this->grain = 'daily';
        }
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'desc' ? 'asc' : 'desc';
        $this->sortBy = $column;
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
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return resolve(ModStatsService::class)->forUser($user, $this->range);
    }

    /**
     * The overview rows in the chosen order.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function mods(): array
    {
        /** @var list<array<string, mixed>> $mods */
        $mods = $this->report['mods'];
        $column = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'downloads';
        $ascending = $this->sortDirection === 'asc';

        usort($mods, fn (array $a, array $b): int => $ascending ? $a[$column] <=> $b[$column] : $b[$column] <=> $a[$column]);

        return $mods;
    }
};
