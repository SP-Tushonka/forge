<?php

declare(strict_types=1);

use App\Jobs\NotifyModSubscribers;
use App\Models\Mod;
use App\Models\ModSubscription;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use App\Notifications\ModUpdatesDigestNotification;
use App\Notifications\ModVersionReleasedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();

    $this->mod = Mod::factory()->create(['published_at' => now()->subDay()]);
    $this->subscriber = User::factory()->create();
    ModSubscription::factory()->for($this->subscriber)->for($this->mod)->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function releaseVersion(Mod $mod, array $attributes = []): ModVersion
{
    return ModVersion::factory()->recycle($mod)->create([
        'version' => '1.1.0',
        'spt_version_constraint' => '',
        'published_at' => now()->subMinute(),
        'disabled' => false,
        'subscribers_notified_at' => null,
        ...$attributes,
    ]);
}

it('tells subscribers once a new version is public', function (): void {
    $version = releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();

    Notification::assertSentTo($this->subscriber, ModVersionReleasedNotification::class,
        fn (ModVersionReleasedNotification $notification): bool => $notification->version === '1.1.0'
            && $notification->modId === $this->mod->id
            && $notification->modName === $this->mod->name
            && $notification->modUrl === $this->mod->detail_url);
    expect($version->fresh()?->subscribers_notified_at)->not->toBeNull();
});

it('announces a version only once', function (): void {
    releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();
    new NotifyModSubscribers()->handle();

    Notification::assertSentToTimes($this->subscriber, ModVersionReleasedNotification::class, 1);
});

it('waits for a scheduled version to go live', function (): void {
    releaseVersion($this->mod, ['published_at' => now()->addHour()]);

    new NotifyModSubscribers()->handle();
    Notification::assertNothingSent();

    $this->travel(2)->hours();
    new NotifyModSubscribers()->handle();

    Notification::assertSentTo($this->subscriber, ModVersionReleasedNotification::class);
});

it('names the SPT version of a tagged release', function (): void {
    SptVersion::factory()->create(['version' => '3.11.4']);
    releaseVersion($this->mod, ['spt_version_constraint' => '3.11.4']);

    new NotifyModSubscribers()->handle();

    Notification::assertSentTo($this->subscriber, ModVersionReleasedNotification::class,
        fn (ModVersionReleasedNotification $notification): bool => $notification->sptVersion !== null);
});

it('ignores disabled versions', function (): void {
    $version = releaseVersion($this->mod, ['disabled' => true]);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
    expect($version->fresh()?->subscribers_notified_at)->toBeNull();
});

it('leaves versions pinned to an unpublished SPT release alone', function (): void {
    $version = releaseVersion($this->mod);
    $spt = SptVersion::factory()->create(['version' => '4.0.0', 'publish_date' => null]);
    $version->sptVersions()->sync([$spt->id => ['pinned_to_spt_publish' => true]]);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
    expect($version->fresh()?->subscribers_notified_at)->toBeNull();
});

it('claims without announcing when the mod is hidden', function (): void {
    $this->mod->update(['disabled' => true]);
    $version = releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
    expect(ModVersion::query()->withoutGlobalScopes()->find($version->id)?->subscribers_notified_at)->not->toBeNull();
});

it('claims without announcing when the mod itself is not published', function (): void {
    $this->mod->update(['published_at' => now()->addDay()]);
    $version = releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
    expect(ModVersion::query()->withoutGlobalScopes()->find($version->id)?->subscribers_notified_at)->not->toBeNull();
});

it('claims stale versions without announcing them', function (): void {
    $version = releaseVersion($this->mod, ['published_at' => now()->subDays(8)]);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
    expect($version->fresh()?->subscribers_notified_at)->not->toBeNull();
});

it('waits for a version\'s SPT gate to actually publish before treating it as stale', function (): void {
    $spt = SptVersion::factory()->create(['version' => '4.0.0', 'publish_date' => now()->addDays(2)]);
    $version = releaseVersion($this->mod, [
        'spt_version_constraint' => '4.0.0',
        'published_at' => now()->subDays(10),
    ]);
    $version->sptVersions()->sync([$spt->id => ['pinned_to_spt_publish' => false]]);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
    expect($version->fresh()?->subscribers_notified_at)->toBeNull();

    $this->travel(3)->days();
    new NotifyModSubscribers()->handle();

    Notification::assertSentTo($this->subscriber, ModVersionReleasedNotification::class);
    expect($version->fresh()?->subscribers_notified_at)->not->toBeNull();
});

it('names only the highest version when several go public together', function (): void {
    releaseVersion($this->mod, ['version' => '1.2.0']);
    releaseVersion($this->mod, ['version' => '1.1.0']);

    new NotifyModSubscribers()->handle();

    Notification::assertSentToTimes($this->subscriber, ModVersionReleasedNotification::class, 1);
    Notification::assertSentTo($this->subscriber, ModVersionReleasedNotification::class,
        fn (ModVersionReleasedNotification $notification): bool => $notification->version === '1.2.0');
});

it('does not notify the owner or authors', function (): void {
    $author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($author);
    ModSubscription::factory()->for($author)->for($this->mod)->create();
    ModSubscription::factory()->for($this->mod->owner)->for($this->mod)->create();
    releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();

    Notification::assertSentTo($this->subscriber, ModVersionReleasedNotification::class);
    Notification::assertNotSentTo([$author, $this->mod->owner], ModVersionReleasedNotification::class);
});

it('does not notify subscribers of other mods', function (): void {
    $bystander = User::factory()->create();
    ModSubscription::factory()->for($bystander)->create();
    releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();

    Notification::assertNotSentTo($bystander, ModVersionReleasedNotification::class);
});

it('does not notify an actively banned subscriber', function (): void {
    $this->subscriber->ban();
    releaseVersion($this->mod);

    new NotifyModSubscribers()->handle();

    Notification::assertNothingSent();
});

describe('email digest', function (): void {
    it('sends a subscriber of one mod a single digest with one release', function (): void {
        releaseVersion($this->mod);

        new NotifyModSubscribers()->handle();

        Notification::assertSentTo($this->subscriber, ModUpdatesDigestNotification::class,
            fn (ModUpdatesDigestNotification $notification): bool => count($notification->releases) === 1);
        Notification::assertSentTimes(ModUpdatesDigestNotification::class, 1);
    });

    it('sends one digest covering every mod that released in the sweep', function (): void {
        $otherMod = Mod::factory()->create(['published_at' => now()->subDay()]);
        ModSubscription::factory()->for($this->subscriber)->for($otherMod)->create();

        releaseVersion($this->mod);
        releaseVersion($otherMod);

        new NotifyModSubscribers()->handle();

        Notification::assertSentToTimes($this->subscriber, ModVersionReleasedNotification::class, 2);
        Notification::assertSentTo($this->subscriber, ModUpdatesDigestNotification::class,
            fn (ModUpdatesDigestNotification $notification): bool => count($notification->releases) === 2);
        Notification::assertSentTimes(ModUpdatesDigestNotification::class, 1);
    });
});
