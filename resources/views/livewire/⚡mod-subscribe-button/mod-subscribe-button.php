<?php

declare(strict_types=1);

use App\Models\ModSubscription;
use App\Models\User;
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

    #[Computed]
    public function isSubscribed(): bool
    {
        $userId = Auth::id();

        return $userId !== null
            && ModSubscription::query()->where('user_id', $userId)->where('mod_id', $this->modId)->exists();
    }

    public function toggle(): void
    {
        $this->authorizeModTab();

        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $subscribed = ! $this->isSubscribed;

        if ($subscribed) {
            $response = Gate::inspect('subscribe', $this->mod);
            if ($response->denied()) {
                Flux::toast(
                    heading: __('Cannot subscribe'),
                    text: $response->message() ?? __('You cannot subscribe to this mod.'),
                    variant: 'danger',
                );

                return;
            }
        }

        if (! $this->withinRateLimit($user)) {
            return;
        }

        if ($subscribed) {
            ModSubscription::query()->insertOrIgnore([
                'user_id' => $user->id,
                'mod_id' => $this->modId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            ModSubscription::query()->where('user_id', $user->id)->where('mod_id', $this->modId)->delete();
        }

        unset($this->isSubscribed);

        Flux::toast(
            heading: $subscribed ? __('Subscribed') : __('Unsubscribed'),
            text: $subscribed
                ? __('You will be notified when this mod releases a new version.')
                : __('You will no longer be notified about new versions of this mod.'),
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

        $key = 'mod-subscription:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 20)) {
            Flux::toast(
                heading: __('Slow down'),
                text: __('You are subscribing too quickly. Please wait :seconds seconds and try again.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
                variant: 'warning',
            );

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
};
