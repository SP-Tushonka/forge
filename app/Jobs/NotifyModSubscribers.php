<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Mod;
use App\Models\ModSubscription;
use App\Models\ModVersion;
use App\Models\Scopes\PublishedScope;
use App\Models\User;
use App\Notifications\ModUpdatesDigestNotification;
use App\Notifications\ModVersionReleasedNotification;
use App\Support\VersionMatcher;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells a mod's subscribers when a new version goes public. A sweep rather than a ModVersion observer: scheduled and
 * SPT-pinned versions go public with no event to hook. Each subscriber gets one on-site alert per released mod, and
 * at most one email digesting every mod that released in this sweep.
 */
final class NotifyModSubscribers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A version first seen public this long after it actually went public is old news, typically a mod back from a
     * long hide.
     */
    private const int STALE_AFTER_DAYS = 7;

    /**
     * Expires the ShouldBeUnique lock after this many seconds, so a worker that dies mid-run cannot block every
     * later sweep forever.
     */
    public int $uniqueFor = 600;

    /**
     * Releases collected across the sweep, keyed by subscriber user ID, for the closing per-user email digest.
     *
     * @var array<int, list<array{mod_id: int, mod_name: string, mod_url: string, version: string, spt_version: string|null}>>
     */
    private array $releasesByUser = [];

    public function handle(): void
    {
        $this->releasesByUser = [];

        ModVersion::query()
            ->withoutGlobalScope(PublishedScope::class)
            ->whereNull('subscribers_notified_at')
            ->where(function (Builder $query): void {
                $query->publiclyVisible()->orWhere(function (Builder $legacy): void {
                    $legacy->legacyPubliclyVisible();
                });
            })
            ->with('latestSptVersion')
            ->get()
            ->reject(fn (ModVersion $version): bool => $version->isPinnedToUnpublishedSptVersion())
            ->groupBy('mod_id')
            ->each(function (Collection $versions, int $modId): void {
                $this->announce($modId, $versions);
            });

        $this->sendDigests();
    }

    /**
     * @param  Collection<int, ModVersion>  $versions
     */
    private function announce(int $modId, Collection $versions): void
    {
        // Row claims make a retry safe; ShouldBeUnique keeps concurrent runs from splitting one mod's versions
        // between them. toBase() keeps the claim from touching updated_at.
        $claimed = $versions->filter(fn (ModVersion $version): bool => ModVersion::query()
            ->withoutGlobalScope(PublishedScope::class)
            ->whereKey($version->id)
            ->whereNull('subscribers_notified_at')
            ->toBase()
            ->update(['subscribers_notified_at' => now()]) === 1);

        $mod = Mod::query()->withoutGlobalScope(PublishedScope::class)->find($modId);
        if ($mod === null || $mod->disabled || ! $mod->isPublished()) {
            return;
        }

        $cutoff = now()->subDays(self::STALE_AFTER_DAYS);

        /** @var ModVersion|null $latest */
        $latest = $claimed
            ->filter(fn (ModVersion $version): bool => $this->wentPublicAt($version)?->isAfter($cutoff) === true)
            ->reduce(fn (?ModVersion $best, ModVersion $version): ModVersion => $best === null || VersionMatcher::isNewer($version->version, $best->version) ? $version : $best);

        if ($latest === null) {
            return;
        }

        $releasers = $mod->additionalAuthors()->pluck('users.id')->push($mod->owner_id)->filter()->all();
        $notification = ModVersionReleasedNotification::forVersion($mod, $latest);

        User::query()
            ->whereIn('id', ModSubscription::query()->where('mod_id', $modId)->select('user_id'))
            ->whereNotIn('id', $releasers)
            ->whereDoesntHave('bans', function (Builder $query): void {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', now());
            })
            ->chunkById(500, function (Collection $subscribers) use ($notification): void {
                Notification::send($subscribers, $notification);

                /** @var User $subscriber */
                foreach ($subscribers as $subscriber) {
                    $this->releasesByUser[$subscriber->id][] = $notification->release();
                }
            });
    }

    /**
     * The later of a version's publish date and, when it was gated on an SPT release, that release's own publish
     * date — a version pinned to an SPT release doesn't actually go public until the SPT release does, which can be
     * long after its own published_at.
     */
    private function wentPublicAt(ModVersion $version): ?CarbonInterface
    {
        $sptPublishDate = $version->latestSptVersion?->publish_date;

        return match (true) {
            $version->published_at === null => $sptPublishDate,
            $sptPublishDate === null => $version->published_at,
            $sptPublishDate->isAfter($version->published_at) => $sptPublishDate,
            default => $version->published_at,
        };
    }

    /**
     * Send the sweep's per-user email digest. via() decides whether mail actually goes out, so the preference is not
     * pre-filtered here.
     */
    private function sendDigests(): void
    {
        foreach (array_chunk(array_keys($this->releasesByUser), 500) as $ids) {
            $users = User::query()->whereIn('id', $ids)->get();

            /** @var User $user */
            foreach ($users as $user) {
                $user->notify(new ModUpdatesDigestNotification($this->releasesByUser[$user->id]));
            }
        }
    }
}
