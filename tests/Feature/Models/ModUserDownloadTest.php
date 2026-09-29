<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Mod;
use App\Models\ModUserDownload;
use App\Models\ModVersion;
use App\Models\TrackingEvent;
use App\Models\User;

function trackDownload(?User $user, ModVersion $version, DateTimeInterface $at): void
{
    TrackingEvent::factory()->create([
        'event_name' => TrackingEventType::MOD_DOWNLOAD->value,
        'visitor_type' => $user instanceof User ? (new User)->getMorphClass() : null,
        'visitor_id' => $user?->id,
        'visitable_type' => (new ModVersion)->getMorphClass(),
        'visitable_id' => $version->id,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

function runDownloadsBackfill(): void
{
    (require database_path('migrations/2026_09_28_000004_backfill_mod_user_downloads_table.php'))->up();
}

describe('record', function (): void {
    it('keeps one row per user and mod, holding the latest download', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();
        $first = ModVersion::factory()->for($mod)->create(['version' => '1.0.0']);
        $second = ModVersion::factory()->for($mod)->create(['version' => '1.1.0']);

        ModUserDownload::record($user, $first);
        ModUserDownload::record($user, $second);

        $row = ModUserDownload::query()->sole();
        expect($row->user_id)->toBe($user->id)
            ->and($row->mod_id)->toBe($mod->id)
            ->and($row->mod_version_id)->toBe($second->id)
            ->and($row->version)->toBe('1.1.0');
    });

    it('ignores versions the public cannot download', function (): void {
        $user = User::factory()->create();

        ModUserDownload::record($user, ModVersion::factory()->create(['disabled' => true]));
        ModUserDownload::record($user, ModVersion::factory()->create(['published_at' => now()->addDay()]));
        ModUserDownload::record($user, ModVersion::factory()->for(Mod::factory()->unpublished())->create());
        ModUserDownload::record($user, ModVersion::factory()->for(Mod::factory()->disabled())->create());

        expect(ModUserDownload::query()->count())->toBe(0);
    });
});

describe('backfill', function (): void {
    it('takes the most recently dated download per user and mod, whatever order the events were written in', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();
        $middle = ModVersion::factory()->for($mod)->create(['version' => '1.0.0']);
        $newest = ModVersion::factory()->for($mod)->create(['version' => '1.1.0']);
        $oldest = ModVersion::factory()->for($mod)->create(['version' => '0.9.0']);
        trackDownload($user, $middle, now()->subDays(3));
        trackDownload($user, $newest, now()->subDay());
        trackDownload($user, $oldest, now()->subDays(9));

        runDownloadsBackfill();

        $row = ModUserDownload::query()->sole();
        expect($row->user_id)->toBe($user->id)
            ->and($row->mod_version_id)->toBe($newest->id)
            ->and($row->version)->toBe('1.1.0');
    });

    it('skips guest downloads and events whose user or version no longer exists', function (): void {
        $version = ModVersion::factory()->create();
        trackDownload(null, $version, now());

        $deletedUser = User::factory()->create();
        trackDownload($deletedUser, $version, now());
        $deletedUser->delete();

        $deletedVersion = ModVersion::factory()->create();
        trackDownload(User::factory()->create(), $deletedVersion, now());
        $deletedVersion->delete();

        runDownloadsBackfill();

        expect(ModUserDownload::query()->count())->toBe(0);
    });

    it('leaves rows recorded since untouched when re-run', function (): void {
        $user = User::factory()->create();
        $version = ModVersion::factory()->create(['version' => '1.0.0']);
        trackDownload($user, $version, now()->subDays(2));
        $current = ModVersion::factory()->for($version->mod)->create(['version' => '2.0.0']);
        ModUserDownload::record($user, $current);

        runDownloadsBackfill();

        expect(ModUserDownload::query()->sole()->version)->toBe('2.0.0');
    });
});

describe('deletion', function (): void {
    it('is removed with the user', function (): void {
        $row = ModUserDownload::factory()->forVersion(ModVersion::factory()->create())->create();

        $row->user->delete();

        expect(ModUserDownload::query()->count())->toBe(0);
    });

    it('outlives a deleted version, keeping the version string', function (): void {
        $version = ModVersion::factory()->create(['version' => '2.3.4']);
        $row = ModUserDownload::factory()->forVersion($version)->create();

        $version->delete();

        expect($row->refresh()->mod_version_id)->toBeNull()
            ->and($row->version)->toBe('2.3.4');
    });
});
