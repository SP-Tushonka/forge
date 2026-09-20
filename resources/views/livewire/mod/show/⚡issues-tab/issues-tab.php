<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Enums\SpamStatus;
use App\Models\ModIssue;
use App\Models\User;
use App\Traits\Livewire\AuthorizesModTab;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Lazy] class extends Component
{
    use AuthorizesModTab;
    use WithPagination;

    #[Url(as: 'issues')]
    public string $state = 'open';

    #[Url(as: 'issueType')]
    public string $type = '';

    #[Url(as: 'issueStatus')]
    public string $status = '';

    #[Url(as: 'issueSearch')]
    public string $search = '';

    #[Url(as: 'issueSort')]
    public string $sort = 'newest';

    public function mount(int $modId): void
    {
        $this->modId = $modId;
        $this->authorizeModTab();
        Gate::authorize('viewAny', [ModIssue::class, $this->mod]);
    }

    /**
     * Issues can be switched off while the tab is open.
     */
    public function hydrate(): void
    {
        Gate::authorize('viewAny', [ModIssue::class, $this->mod]);
    }

    public function updated(string $property): void
    {
        // A status filter from the other side of the toggle would match nothing
        if ($property === 'state') {
            $this->status = '';
        }

        $this->resetPage(pageName: 'issuePage');
    }

    /**
     * @return LengthAwarePaginator<int, ModIssue>
     */
    #[Computed]
    public function issues(): LengthAwarePaginator
    {
        $search = mb_trim($this->search);
        $type = ModIssueType::tryFrom($this->type);
        $status = ModIssueStatus::tryFrom($this->status);

        return $this->mod->issues()
            ->with('user')
            ->withCount([
                'comments' => function (Builder $query): void {
                    $query->where('spam_status', SpamStatus::CLEAN->value)->whereNull('deleted_at');
                },
                'reactions',
            ])
            ->when(
                $this->state === 'closed',
                function (Builder $query): void {
                    $query->closed();
                },
                function (Builder $query): void {
                    $query->open();
                },
            )
            ->when($type instanceof ModIssueType, function (Builder $query) use ($type): void {
                $query->where('type', $type);
            })
            ->when($status instanceof ModIssueStatus, function (Builder $query) use ($status): void {
                $query->where('status', $status);
            })
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereLike('title', '%'.$search.'%');
            })
            ->when($this->sort === 'updated', function (Builder $query): void {
                $query->orderByDesc('last_activity_at');
            })
            ->when($this->sort === 'reactions', function (Builder $query): void {
                $query->orderByDesc('reactions_count');
            })
            ->orderByDesc('number')
            ->paginate(perPage: 20, pageName: 'issuePage');
    }

    #[Computed]
    public function openCount(): int
    {
        return $this->mod->issues()->open()->count();
    }

    #[Computed]
    public function closedCount(): int
    {
        return $this->mod->issues()->closed()->count();
    }

    /**
     * The types the mod accepts, plus any type its existing issues already use, so switching a type off never
     * hides the filter for what was filed under it.
     *
     * @return list<ModIssueType>
     */
    #[Computed]
    public function typeOptions(): array
    {
        $filed = $this->mod->issues()->distinct()->pluck('type')->all();

        return array_values(array_filter(
            ModIssueType::cases(),
            fn (ModIssueType $type): bool => $this->mod->allowsIssueType($type) || in_array($type, $filed, true),
        ));
    }

    /**
     * @return list<ModIssueStatus>
     */
    #[Computed]
    public function statusOptions(): array
    {
        return $this->state === 'closed' ? ModIssueStatus::closed() : ModIssueStatus::open();
    }

    #[Computed]
    public function createResponse(): Response
    {
        return Gate::inspect('create', [ModIssue::class, $this->mod]);
    }

    #[Computed]
    public function isModMuted(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->hasMuted($this->mod);
    }

    public function toggleModMute(): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return;
        }

        if ($this->isModMuted) {
            $user->unmute($this->mod);
        } else {
            $user->mute($this->mod);
        }

        unset($this->isModMuted);
    }
};
