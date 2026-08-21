<?php

declare(strict_types=1);

use App\Models\Ban;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Scout\Jobs\MakeSearchable;
use Laravel\Scout\Jobs\RemoveFromSearch;

it('forgets the cached ban state when a ban is created', function (): void {
    $user = User::factory()->create();

    Cache::put(User::banStateCacheKey($user->id), 'none', 60);

    $user->ban(['comment' => 'Test ban']);

    expect(Cache::get(User::banStateCacheKey($user->id)))->toBeNull();
});

it('forgets the cached ban state when a ban is updated', function (): void {
    $user = User::factory()->create();
    $ban = $user->ban(['comment' => 'Test ban']);

    Cache::put(User::banStateCacheKey($user->id), 'permanent', 60);

    $ban->update(['expired_at' => now()->addDay()]);

    expect(Cache::get(User::banStateCacheKey($user->id)))->toBeNull();
});

it('forgets the cached ban state when a ban is deleted', function (): void {
    $user = User::factory()->create();
    $user->ban(['comment' => 'Test ban']);

    Cache::put(User::banStateCacheKey($user->id), 'permanent', 60);

    $user->unban();

    expect(Cache::get(User::banStateCacheKey($user->id)))->toBeNull();
});

it('leaves user ban state untouched for ip bans', function (): void {
    $user = User::factory()->create();

    Cache::put(User::banStateCacheKey($user->id), 'none', 60);

    Ban::query()->create(['ip' => '127.0.0.2']);

    expect(Cache::get(User::banStateCacheKey($user->id)))->toBe('none');
});

describe('search index sync', function (): void {
    beforeEach(function (): void {
        // The collection Scout engine no-ops writes and re-evaluates shouldBeSearchable() at read time, so index
        // staleness is structurally unreproducible. Forcing the queued path makes the sync assertable as a job.
        config(['scout.queue' => true]);
    });

    it('removes a user from the search index when they are banned', function (): void {
        $user = User::factory()->create();

        Queue::fake();

        $user->ban(['comment' => 'Test ban']);

        Queue::assertPushed(RemoveFromSearch::class);
    });

    it('restores a user to the search index when they are unbanned', function (): void {
        $user = User::factory()->create();
        $user->ban(['comment' => 'Test ban']);

        Queue::fake();

        $user->unban();

        Queue::assertPushed(MakeSearchable::class);
    });

    it('leaves the search index alone for ip bans', function (): void {
        Queue::fake();

        Ban::query()->create(['ip' => '127.0.0.2']);

        Queue::assertNotPushed(MakeSearchable::class);
        Queue::assertNotPushed(RemoveFromSearch::class);
    });
});
