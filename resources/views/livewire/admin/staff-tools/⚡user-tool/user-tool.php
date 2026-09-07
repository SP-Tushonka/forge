<?php

declare(strict_types=1);

use App\Actions\Staff\ChangeUserEmail;
use App\Actions\Staff\InvalidateUserPassword;
use App\Actions\Staff\LockAccount;
use App\Actions\Staff\RemoveUserEmail;
use App\Actions\Staff\RemoveUserPhoto;
use App\Actions\Staff\RemoveUserTwoFactor;
use App\Actions\Staff\SendUserPasswordReset;
use App\Actions\Staff\UnlinkDiscordConnection;
use App\Enums\UserImageType;
use App\Exceptions\StaffActionException;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** Free text search: name, email, user id or Discord provider id. */
    public string $search = '';

    /** The resolved target, kept in the URL so staff can share a link. */
    #[Url]
    public ?int $userId = null;

    /** Whether the confirm modal is open. */
    public bool $showActionModal = false;

    /** Which action the open modal will run. */
    public string $pendingAction = '';

    /** Mandatory justification, stored on the tracking event. */
    public string $reason = '';

    /** Only used by changeEmail. */
    public string $newEmail = '';

    /** Only used by removeEmail and lockAccount: whether recovery stays possible. */
    public bool $recoverable = true;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');
    }

    /**
     * Search results. Empty until the staff member types something.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = mb_trim($this->search);

        if ($term === '') {
            return new Collection;
        }

        return User::query()
            ->with(['role', 'bans', 'oAuthConnections'])
            ->where(function (Builder $query) use ($term): void {
                $query->whereLike('name', '%'.$term.'%')
                    ->orWhereLike('email', '%'.$term.'%')
                    ->orWhereHas('oAuthConnections', function (Builder $connections) use ($term): void {
                        $connections->where('provider_id', $term);
                    });

                // Postgres refuses a non-numeric comparison against bigint columns.
                if (ctype_digit($term)) {
                    $query->orWhere('id', (int) $term);
                }
            })
            ->orderBy('name')
            ->limit(15)
            ->get();
    }

    /**
     * The single user being acted upon, with every relation the summary card reads
     * eager-loaded — Model::shouldBeStrict() is active in production.
     */
    #[Computed]
    public function target(): ?User
    {
        if ($this->userId === null) {
            return null;
        }

        return User::query()
            ->with(['role', 'bans', 'oAuthConnections'])
            ->find($this->userId);
    }

    public function selectUser(int $userId): void
    {
        $this->userId = $userId;
        $this->search = '';
    }

    public function clearUser(): void
    {
        $this->userId = null;
    }

    public function confirm(string $action): void
    {
        $this->pendingAction = $action;
        $this->showActionModal = true;
        $this->reason = '';
        $this->newEmail = '';
        $this->recoverable = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showActionModal = false;
        $this->pendingAction = '';
        $this->reason = '';
        $this->newEmail = '';
    }

    public function runAction(): void
    {
        $target = $this->target;
        $staff = auth()->user();

        if ($target === null || ! $staff instanceof User) {
            return;
        }

        $rules = ['reason' => 'required|string|max:1000'];

        if ($this->pendingAction === 'changeEmail') {
            $rules['newEmail'] = 'required|email|max:255';
        }

        $this->validate($rules);

        try {
            match ($this->pendingAction) {
                'changeEmail' => resolve(ChangeUserEmail::class)->execute($staff, $target, $this->newEmail, $this->reason),
                'removeEmail' => resolve(RemoveUserEmail::class)->execute($staff, $target, $this->recoverable, $this->reason),
                'sendPasswordReset' => resolve(SendUserPasswordReset::class)->execute($staff, $target, $this->reason),
                'invalidatePassword' => resolve(InvalidateUserPassword::class)->execute($staff, $target, $this->reason),
                'removeTwoFactor' => resolve(RemoveUserTwoFactor::class)->execute($staff, $target, $this->reason),
                'unlinkDiscord' => resolve(UnlinkDiscordConnection::class)->execute($staff, $target, $this->reason),
                'lockAccount' => resolve(LockAccount::class)->execute($staff, $target, $this->recoverable, $this->reason),
                'removeAvatar' => resolve(RemoveUserPhoto::class)->execute($staff, $target, UserImageType::ProfilePhoto, $this->reason),
                'removeCover' => resolve(RemoveUserPhoto::class)->execute($staff, $target, UserImageType::CoverPhoto, $this->reason),
                default => throw StaffActionException::because('Unknown action.'),
            };
        } catch (StaffActionException $exception) {
            $this->addError('action', $exception->getMessage());

            return;
        }

        unset($this->target);

        Flux::toast(heading: __('Done'), text: __('The action completed successfully.'), variant: 'success');

        $this->cancel();
    }
};
