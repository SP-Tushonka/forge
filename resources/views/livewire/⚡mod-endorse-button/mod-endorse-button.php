<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\ModEndorsementService;
use App\Traits\Livewire\AuthorizesModTab;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use AuthorizesModTab;

    public function mount(int $modId): void
    {
        $this->modId = $modId;

        $this->authorizeModTab();
    }

    /**
     * Whether the viewer's endorsement of this mod currently stands.
     */
    #[Computed]
    public function isEndorsed(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && resolve(ModEndorsementService::class)->isEndorsedBy($user, $this->mod);
    }

    /**
     * Why the viewer may not endorse, or null when they may.
     *
     * Rendered into a disabled button's tooltip so the refusal is explained before the click rather than as a 403
     * after it.
     */
    #[Computed]
    public function denialReason(): ?string
    {
        if (! Auth::check()) {
            return null;
        }

        $response = Gate::inspect('endorse', $this->mod);

        return $response->allowed() ? null : $response->message();
    }

    public function toggle(): void
    {
        $this->authorizeModTab();

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $response = Gate::inspect('endorse', $this->mod);
        if ($response->denied()) {
            Flux::toast(
                heading: __('Cannot endorse'),
                text: $response->message() ?? __('You cannot endorse this mod.'),
                variant: 'danger',
            );

            return;
        }

        if (! $this->withinRateLimit($user)) {
            return;
        }

        $endorsed = resolve(ModEndorsementService::class)->toggle($user, $this->mod);

        unset($this->isEndorsed, $this->denialReason);

        $this->dispatch('mod-endorsement-changed', modId: $this->modId, endorsed: $endorsed);

        Flux::toast(
            heading: $endorsed ? __('Endorsed') : __('Endorsement withdrawn'),
            text: $endorsed
                ? __('Thanks for endorsing this mod.')
                : __('Your endorsement of this mod has been withdrawn.'),
            variant: 'success',
        );
    }

    /**
     * Throttle the toggle against click spam. Staff are exempt.
     */
    private function withinRateLimit(User $user): bool
    {
        if ($user->isModOrAdmin()) {
            return true;
        }

        $key = 'mod-endorsement:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, config()->integer('endorsements.rate_limiting.max_attempts', 20))) {
            Flux::toast(
                heading: __('Slow down'),
                text: __('You are endorsing too quickly. Please wait :seconds seconds and try again.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
                variant: 'warning',
            );

            return false;
        }

        RateLimiter::hit($key, config()->integer('endorsements.rate_limiting.duration_seconds', 60));

        return true;
    }
};
