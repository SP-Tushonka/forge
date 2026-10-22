<?php

declare(strict_types=1);

use App\Models\IgnoredUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function stopIgnoring(int $userId): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $user->unignoreUser($userId);

        Flux::toast(heading: __('No longer ignored'), text: __('Their content is visible across the site again.'), variant: 'success');
    }

    /**
     * @return LengthAwarePaginator<int, IgnoredUser>
     */
    #[Computed]
    public function ignoredUsers(): LengthAwarePaginator
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return new LengthAwarePaginator([], 0, 20);
        }

        return $user->ignoredUsers()
            ->with('ignoredUser')
            ->latest()
            ->paginate(20, pageName: 'ignoredUsersPage');
    }
};
