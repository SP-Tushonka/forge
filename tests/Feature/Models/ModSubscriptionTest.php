<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModSubscription;
use App\Models\ModVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

describe('mod subscription rows', function (): void {
    it('keeps one row per user per mod', function (): void {
        $subscription = ModSubscription::factory()->create();

        expect(fn () => ModSubscription::factory()->create([
            'user_id' => $subscription->user_id,
            'mod_id' => $subscription->mod_id,
        ]))->toThrow(UniqueConstraintViolationException::class);
    });

    it('is deleted with the user', function (): void {
        $subscription = ModSubscription::factory()->create();

        $subscription->user->delete();

        expect(ModSubscription::query()->count())->toBe(0);
    });

    it('is deleted with the mod', function (): void {
        $subscription = ModSubscription::factory()->create();

        Mod::query()->withoutGlobalScopes()->whereKey($subscription->mod_id)->delete();

        expect(ModSubscription::query()->count())->toBe(0);
    });
});

describe('subscribers_notified_at backfill', function (): void {
    it('marks every already-published version as already announced', function (): void {
        $version = ModVersion::factory()->create(['subscribers_notified_at' => null]);

        (require database_path('migrations/2026_09_29_000003_backfill_mod_versions_subscribers_notified_at.php'))->up();

        expect(DB::table('mod_versions')->where('id', $version->id)->value('subscribers_notified_at'))->not->toBeNull();
    });

    it('leaves a draft version unclaimed so it is announced when it is published', function (): void {
        $version = ModVersion::factory()->create(['subscribers_notified_at' => null, 'published_at' => null]);

        (require database_path('migrations/2026_09_29_000003_backfill_mod_versions_subscribers_notified_at.php'))->up();

        expect(DB::table('mod_versions')->where('id', $version->id)->value('subscribers_notified_at'))->toBeNull();
    });

    it('leaves a scheduled version unclaimed so it is announced when it goes live', function (): void {
        $version = ModVersion::factory()->create(['subscribers_notified_at' => null, 'published_at' => now()->addDay()]);

        (require database_path('migrations/2026_09_29_000003_backfill_mod_versions_subscribers_notified_at.php'))->up();

        expect(DB::table('mod_versions')->where('id', $version->id)->value('subscribers_notified_at'))->toBeNull();
    });
});
