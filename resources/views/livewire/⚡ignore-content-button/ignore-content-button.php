<?php

declare(strict_types=1);

use App\Models\User;
use App\Traits\Livewire\ThrottlesIgnoreToggles;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use ThrottlesIgnoreToggles;

    public User $user;

    public string $size = 'sm';

    public bool $showModal = false;

    /**
     * "owned" or "coauthored".
     */
    public string $scope = 'owned';

    #[Computed]
    public function isIgnoring(): bool
    {
        return Auth::user()?->ignoredUsers()->where('ignored_user_id', $this->user->id)->exists() ?? false;
    }

    public function openModal(): void
    {
        $this->scope = 'owned';
        $this->showModal = true;
    }

    public function confirmIgnore(): void
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User || Gate::denies('ignoreContent', $this->user) || ! $this->withinIgnoreRateLimit($viewer)) {
            return;
        }

        $viewer->ignoreUser($this->user, includeCoauthored: $this->scope === 'coauthored');

        Flux::toast(heading: __('Content ignored'), text: __('Content from :name is now hidden from you.', ['name' => $this->user->name]), variant: 'success');

        $this->reloadProfile();
    }

    public function stopIgnoring(): void
    {
        $viewer = Auth::user();

        if (! $viewer instanceof User || ! $this->withinIgnoreRateLimit($viewer)) {
            return;
        }

        $viewer->unignoreUser($this->user);

        Flux::toast(heading: __('No longer ignored'), text: __('Content from :name is visible again.', ['name' => $this->user->name]), variant: 'success');

        $this->reloadProfile();
    }

    private function reloadProfile(): void
    {
        $this->redirect(route('user.show', ['userId' => $this->user->id, 'slug' => $this->user->slug]), navigate: true);
    }
};
