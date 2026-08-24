<?php

declare(strict_types=1);

use App\Support\HomepageSectionCache;
use Illuminate\Support\Facades\Cache;

afterEach(function (): void {
    Cache::clear();
});

describe('HomepageSectionCache', function (): void {
    it('caches each section under its own key', function (): void {
        $ids = HomepageSectionCache::remember(HomepageSectionCache::FEATURED, fn (): array => [1, 2]);

        expect($ids)->toBe([1, 2])
            ->and(Cache::has('homepage:sections:featured'))->toBeTrue();
    });

    it('returns the cached list without invoking the callback again', function (): void {
        $calls = 0;
        $callback = function () use (&$calls): array {
            $calls++;

            return [$calls];
        };

        HomepageSectionCache::remember(HomepageSectionCache::NEWEST, $callback);
        $ids = HomepageSectionCache::remember(HomepageSectionCache::NEWEST, $callback);

        expect($calls)->toBe(1)
            ->and($ids)->toBe([1]);
    });

    it('caches the endorsed sections under their own keys as id => count maps', function (): void {
        $week = HomepageSectionCache::remember(HomepageSectionCache::ENDORSED_WEEK, fn (): array => [7 => 3, 9 => 1]);
        HomepageSectionCache::remember(HomepageSectionCache::ENDORSED_MONTH, fn (): array => [9 => 12]);
        HomepageSectionCache::remember(HomepageSectionCache::ENDORSED_ALL, fn (): array => [4 => 99]);

        expect($week)->toBe([7 => 3, 9 => 1])
            ->and(Cache::get('homepage:sections:endorsed:7d'))->toBe([7 => 3, 9 => 1])
            ->and(Cache::get('homepage:sections:endorsed:30d'))->toBe([9 => 12])
            ->and(Cache::get('homepage:sections:endorsed:all'))->toBe([4 => 99])
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:endorsed:7d'))->toBeTrue()
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:endorsed:30d'))->toBeTrue()
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:endorsed:all'))->toBeTrue();
    });

    it('flushes the mod sections without touching the comment feed', function (): void {
        HomepageSectionCache::remember(HomepageSectionCache::FEATURED, fn (): array => [1]);
        HomepageSectionCache::remember(HomepageSectionCache::NEWEST, fn (): array => [2]);
        HomepageSectionCache::remember(HomepageSectionCache::UPDATED, fn (): array => [3]);
        HomepageSectionCache::remember(HomepageSectionCache::ENDORSED_WEEK, fn (): array => [5 => 1]);
        HomepageSectionCache::remember(HomepageSectionCache::ENDORSED_MONTH, fn (): array => [6 => 2]);
        HomepageSectionCache::remember(HomepageSectionCache::ENDORSED_ALL, fn (): array => [7 => 3]);
        HomepageSectionCache::remember(HomepageSectionCache::COMMENTS, fn (): array => [4]);

        HomepageSectionCache::flushModSections();

        expect(Cache::has('homepage:sections:featured'))->toBeFalse()
            ->and(Cache::has('homepage:sections:newest'))->toBeFalse()
            ->and(Cache::has('homepage:sections:updated'))->toBeFalse()
            ->and(Cache::has('homepage:sections:endorsed:7d'))->toBeFalse()
            ->and(Cache::has('homepage:sections:endorsed:30d'))->toBeFalse()
            ->and(Cache::has('homepage:sections:endorsed:all'))->toBeFalse()
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:endorsed:7d'))->toBeFalse()
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:endorsed:30d'))->toBeFalse()
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:endorsed:all'))->toBeFalse()
            ->and(Cache::has('homepage:sections:comments'))->toBeTrue();
    });

    it('flushes the comment feed without touching the mod sections', function (): void {
        HomepageSectionCache::remember(HomepageSectionCache::FEATURED, fn (): array => [1]);
        HomepageSectionCache::remember(HomepageSectionCache::COMMENTS, fn (): array => [4]);

        HomepageSectionCache::flushComments();

        expect(Cache::has('homepage:sections:comments'))->toBeFalse()
            ->and(Cache::has('homepage:sections:featured'))->toBeTrue();
    });

    it('recomputes a section after a flush', function (): void {
        $calls = 0;
        $callback = function () use (&$calls): array {
            $calls++;

            return [$calls];
        };

        HomepageSectionCache::remember(HomepageSectionCache::UPDATED, $callback);
        HomepageSectionCache::flushModSections();

        $ids = HomepageSectionCache::remember(HomepageSectionCache::UPDATED, $callback);

        expect($calls)->toBe(2)
            ->and($ids)->toBe([2])
            ->and(Cache::has('illuminate:cache:flexible:created:homepage:sections:updated'))->toBeTrue();
    });
});
