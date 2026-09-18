<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Emoji;
use App\Models\Mod;
use App\Models\User;
use App\Services\ReactionSummaryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->service = resolve(ReactionSummaryService::class);
    $this->heart = Emoji::query()->where('shortcode', 'heart')->sole();
    $this->fire = Emoji::query()->where('shortcode', 'fire')->sole();
});

describe('ReactionSummaryService counts', function (): void {
    it('groups counts per reactable and per emoji', function (): void {
        $modA = Mod::factory()->create();
        $modB = Mod::factory()->create();

        foreach (User::factory()->count(3)->create() as $user) {
            $modA->reactions()->create(['user_id' => $user->id, 'emoji_id' => $this->heart->id]);
        }
        $modA->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->fire->id]);
        $modB->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->fire->id]);

        $summary = $this->service->for(Mod::class, [$modA->id, $modB->id], null);

        expect($summary->countsFor($modA->id))->toEqualCanonicalizing([
            $this->heart->id => 3,
            $this->fire->id => 1,
        ])->and($summary->countsFor($modB->id))->toBe([$this->fire->id => 1]);
    });

    it('returns an empty array for a reactable with no reactions', function (): void {
        $mod = Mod::factory()->create();

        expect($this->service->for(Mod::class, [$mod->id], null)->countsFor($mod->id))->toBe([]);
    });

    it('reports which emoji the viewer picked', function (): void {
        $mod = Mod::factory()->create();
        $viewer = User::factory()->create();
        $other = User::factory()->create();

        $mod->reactions()->create(['user_id' => $viewer->id, 'emoji_id' => $this->heart->id]);
        $mod->reactions()->create(['user_id' => $other->id, 'emoji_id' => $this->fire->id]);

        $summary = $this->service->for(Mod::class, [$mod->id], $viewer);

        expect($summary->mineFor($mod->id))->toBe([$this->heart->id]);
    });

    it('reports nothing as mine for a guest', function (): void {
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->heart->id]);

        expect($this->service->for(Mod::class, [$mod->id], null)->mineFor($mod->id))->toBe([]);
    });

    it('does not leak counts from another reactable type', function (): void {
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $this->heart->id]);

        expect($this->service->for(Comment::class, [$mod->id], null)->countsFor($mod->id))->toBe([]);
    });
});

describe('ReactionSummaryService query budget', function (): void {
    it('uses two queries for a page of twenty four mods', function (): void {
        $mods = Mod::factory()->count(24)->create();
        $viewer = User::factory()->create();

        foreach ($mods as $mod) {
            $mod->reactions()->create(['user_id' => $viewer->id, 'emoji_id' => $this->heart->id]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->service->for(Mod::class, $mods->pluck('id')->all(), $viewer);

        expect(DB::getQueryLog())->toHaveCount(2);
    });

    it('runs no queries at all for an empty id list', function (): void {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $summary = $this->service->for(Mod::class, [], null);

        expect(DB::getQueryLog())->toHaveCount(0)
            ->and($summary->counts)->toBe([]);
    });
});

describe('ReactionSummaryService whitelist', function (): void {
    it('returns only enabled emoji in staff order', function (): void {
        Emoji::query()->where('shortcode', 'tada')->update(['enabled' => false]);
        $this->service->forgetWhitelist();

        expect($this->service->whitelist()->pluck('shortcode')->all())
            ->toBe(['heart', 'thumbsup', 'joy', 'fire']);
    });

    it('serves the whitelist from cache on repeat calls', function (): void {
        $this->service->whitelist();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->service->whitelist();

        expect(DB::getQueryLog())->toHaveCount(0);
    });

    it('caches plain attribute rows, never eloquent models', function (): void {
        $this->service->forgetWhitelist();
        $this->service->whitelist();

        $cached = Cache::get(ReactionSummaryService::WHITELIST_CACHE_KEY);

        expect($cached)->toBeArray()->not->toBeEmpty();

        foreach ($cached as $row) {
            expect($row)->toBeArray();

            foreach ($row as $value) {
                expect(is_object($value))->toBeFalse('Cached whitelist rows must hold no objects.');
            }
        }
    });

    it('survives a cache driver that serializes, which the array store in tests does not', function (): void {
        $this->service->forgetWhitelist();
        $this->service->whitelist();

        // Redis, file and database stores all serialize. Round-tripping the payload here reproduces that without
        // depending on a driver, and is what catches a cached Eloquent model turning into __PHP_Incomplete_Class.
        $payload = Cache::get(ReactionSummaryService::WHITELIST_CACHE_KEY);
        $revived = unserialize(serialize($payload));

        Cache::forever(ReactionSummaryService::WHITELIST_CACHE_KEY, $revived);

        $whitelist = $this->service->whitelist();

        expect($whitelist)->toHaveCount(5)
            ->and($whitelist->first())->toBeInstanceOf(Emoji::class)
            ->and($whitelist->pluck('shortcode')->all())->toBe(['heart', 'thumbsup', 'joy', 'fire', 'tada']);
    });

    it('rehydrates usable models from the cached rows', function (): void {
        $this->service->forgetWhitelist();
        $this->service->whitelist();

        $heart = $this->service->whitelist()->firstWhere('shortcode', 'heart');

        expect($heart)->toBeInstanceOf(Emoji::class)
            ->and($heart->enabled)->toBeTrue()
            ->and($heart->sort_order)->toBe(1)
            ->and($heart->image_url)->toBe(asset('vendor/twemoji/svg/2764.svg'));
    });
});
