<?php

declare(strict_types=1);

use App\Models\User;
use App\Traits\Livewire\AuthorizesModTab;
use App\Traits\Livewire\ThrottlesIgnoreToggles;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use AuthorizesModTab;
    use ThrottlesIgnoreToggles;

    public function mount(int $modId): void
    {
        $this->modId = $modId;

        $this->authorizeModTab();
    }

    #[Computed]
    public function isIgnored(): bool
    {
        return Auth::user()?->ignoredMods()->where('mod_id', $this->modId)->exists() ?? false;
    }

    /**
     * Reloads the mod page afterwards, so the page swaps to or from the ignored-content notice.
     */
    public function toggle(): void
    {
        $this->authorizeModTab();

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $ignoring = ! $this->isIgnored;

        if ($ignoring) {
            $response = Gate::inspect('ignore', $this->mod);
            if ($response->denied()) {
                Flux::toast(
                    heading: __('Cannot ignore'),
                    text: $response->message() ?? __('You cannot ignore this mod.'),
                    variant: 'danger',
                );

                return;
            }
        }

        if (! $this->withinIgnoreRateLimit($user)) {
            return;
        }

        $ignoring ? $user->ignoreMod($this->mod) : $user->unignoreMod($this->mod);

        $this->redirect($this->mod->detail_url, navigate: true);
    }
};
