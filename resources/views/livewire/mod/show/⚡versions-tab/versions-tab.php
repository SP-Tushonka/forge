<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModVersion;
use App\Traits\Livewire\AuthorizesModTab;
use App\Traits\Livewire\ModeratesModVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Livewire\WithPagination;

new #[Lazy] class extends Component
{
    use AuthorizesModTab;
    use ModeratesModVersion;
    use WithPagination;

    /**
     * Mount the component.
     */
    public function mount(int $modId): void
    {
        $this->modId = $modId;
        $this->authorizeModTab();
    }

    /**
     * Get the mod.
     */
    #[Computed]
    public function mod(): Mod
    {
        return Mod::query()
            ->with(['latestVersion', 'latestLegacyVersion'])
            ->findOrFail($this->modId);
    }

    /**
     * Get the latest version ID for comparison in version cards.
     */
    #[Computed]
    public function latestVersionId(): ?int
    {
        return ($this->mod->latestVersion ?? $this->mod->latestLegacyVersion)?->id;
    }

    /**
     * The mod's versions.
     *
     * @return LengthAwarePaginator<int, ModVersion>
     */
    #[Computed]
    public function versions(): LengthAwarePaginator
    {
        $user = auth()->user();

        return $this->mod
            ->versions()
            ->with(['latestSptVersion', 'sptVersions', 'latestDependenciesResolved.mod:id,name,slug,thumbnail,thumbnail_hash,owner_id', 'latestDependenciesResolved.mod.owner.role'])
            ->unless($user?->can('viewAny', [ModVersion::class, $this->mod]), function (Builder $query): void {
                // Include both modern versions (with SPT tags) and legacy versions (empty constraint)
                $query->where(function (Builder $q): void {
                    $q->publiclyVisible()->orWhere(function (Builder $legacy): void {
                        $legacy->legacyPubliclyVisible();
                    });
                });
            })
            ->withCount([
                'compatibleAddonVersions as compatible_addons_count' => function (Builder $query) use ($user): void {
                    // Only count published, enabled addons for non-privileged users
                    $query->whereHas('addon', function (Builder $addonQuery) use ($user): void {
                        $addonQuery->whereNull('detached_at');

                        if (! $user?->isModOrAdmin()) {
                            $addonQuery->where('disabled', false)->whereNotNull('published_at')->where('published_at', '<=', now());
                        }
                    });
                },
            ])
            ->paginate(perPage: 6, pageName: 'versionPage')
            ->fragment('versions');
    }

    /**
     * Completed issues fixed by the versions on this page, keyed by version string.
     *
     * @return array<string, list<ModIssue>>
     */
    #[Computed]
    public function fixedIssues(): array
    {
        if (! Gate::allows('viewAny', [ModIssue::class, $this->mod])) {
            return [];
        }

        $versions = $this->versions->getCollection()->pluck('version')->all();

        if ($versions === []) {
            return [];
        }

        $fixed = [];

        $issues = $this->mod->issues()
            ->where('status', ModIssueStatus::Completed)
            ->whereIn('fixed_version', $versions)
            ->orderBy('number')
            ->get();

        foreach ($issues as $issue) {
            $fixed[(string) $issue->fixed_version][] = $issue;
        }

        return $fixed;
    }
};
