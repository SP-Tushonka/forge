<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Ban;
use App\Models\ModIssue;
use App\Models\User;
use App\Services\BanIdentifierService;
use App\Services\ModListService;
use App\Services\ThumbnailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

final readonly class UserObserver
{
    public function __construct(
        private ModListService $modListService,
        private ThumbnailService $thumbnailService,
        private BanIdentifierService $banIdentifierService,
    ) {}

    /**
     * Handle the User "created" event.
     *
     * Every new user gets an auto-created, immutable default Favourites list.
     */
    public function created(User $user): void
    {
        $this->modListService->ensureFavouritesFor($user);
    }

    /**
     * Handle the User "deleting" event.
     *
     * Removes the user's profile photo, cover photo, and their variants from disk.
     */
    public function deleting(User $user): void
    {
        /** @var string $disk */
        $disk = config('filesystems.asset_upload', 'public');

        if ($user->profile_photo_path) {
            Storage::disk($disk)->delete($user->profile_photo_path);
        }

        if ($user->cover_photo_path) {
            Storage::disk($disk)->delete($user->cover_photo_path);
        }

        $this->thumbnailService->deleteVariants($disk, $user->profile_photo_variants);
        $this->thumbnailService->deleteVariants($disk, $user->cover_photo_variants);

        // The mod_issues cascade would skip ModIssue's forceDeleting hook and orphan the discussion on their issues.
        ModIssue::withTrashed()->where('user_id', $user->id)->each(function (ModIssue $issue): void {
            $issue->forceDelete();
        });

        // Bans outlive the account, so refresh their copy of its emails and IPs while the rows holding them still exist.
        $user->bans()->where(fn (Builder $query) => $query->notExpired())->each(function (Ban $ban) use ($user): void {
            $this->banIdentifierService->capture($ban, $user);
        });
    }
}
