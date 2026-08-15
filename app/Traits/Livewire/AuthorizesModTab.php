<?php

declare(strict_types=1);

namespace App\Traits\Livewire;

use App\Models\Mod;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Checks that a mod tab can actually be accessed by someone
 * Prevents data leaks
 */
trait AuthorizesModTab
{
    #[Locked]
    public int $modId;

    /**
     * Re authorize on every subsequent request. Livewire derives this hook name from the
     * trait basename, so renaming the trait without renaming this silently disables it
     */
    public function hydrateAuthorizesModTab(): void
    {
        if (isset($this->modId)) {
            $this->authorizeModTab();
        }
    }

    /**
     * The tabs mod. Components needing eager loads redeclare this.
     */
    #[Computed]
    public function mod(): Mod
    {
        return Mod::query()->findOrFail($this->modId);
    }

    /**
     * Authorize that the current user may view the tab mod
     */
    protected function authorizeModTab(): void
    {
        Gate::authorize('view', $this->mod);
    }
}
