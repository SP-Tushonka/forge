<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\User;
use App\Services\ModEndorsementService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->service = resolve(ModEndorsementService::class);
});

describe('endorsing', function (): void {
    it('creates the row, anchors it to now and moves the counter up by one', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        expect($this->service->endorse($user, $mod))->toBeTrue();

        $endorsement = ModEndorsement::query()->sole();

        expect($endorsement->user_id)->toBe($user->id)
            ->and($endorsement->mod_id)->toBe($mod->id)
            ->and($endorsement->endorsed_at->toIso8601String())->toBe(now()->toIso8601String())
            ->and($endorsement->revoked_at)->toBeNull()
            ->and($mod->refresh()->endorsements_count)->toBe(1);
    });

    it('does not double count when the same user endorses twice', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        expect($this->service->endorse($user, $mod))->toBeTrue()
            ->and($this->service->endorse($user, $mod))->toBeFalse()
            ->and(ModEndorsement::query()->count())->toBe(1)
            ->and($mod->refresh()->endorsements_count)->toBe(1);
    });

    it('reports the resulting state through toggle', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        expect($this->service->toggle($user, $mod))->toBeTrue()
            ->and($mod->refresh()->endorsements_count)->toBe(1)
            ->and($this->service->toggle($user, $mod))->toBeFalse()
            ->and($mod->refresh()->endorsements_count)->toBe(0);
    });
});

describe('withdrawing', function (): void {
    it('sets revoked_at, keeps the row and moves the counter down by one', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);

        $this->travel(3)->days();

        expect($this->service->revoke($user, $mod))->toBeTrue();

        $endorsement = ModEndorsement::query()->sole();

        expect($endorsement->revoked_at?->toIso8601String())->toBe(now()->toIso8601String())
            ->and(ModEndorsement::query()->count())->toBe(1)
            ->and($mod->refresh()->endorsements_count)->toBe(0);
    });

    it('does not double decrement when withdrawn twice', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);
        $this->service->endorse(User::factory()->create(), $mod);

        expect($this->service->revoke($user, $mod))->toBeTrue()
            ->and($this->service->revoke($user, $mod))->toBeFalse()
            ->and($mod->refresh()->endorsements_count)->toBe(1);
    });

    it('never takes a drifted counter below zero', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);

        // The counter has drifted below the true number of standing endorsements. endorsements_count is unsigned,
        // so an unguarded decrement would either error or wrap to a huge number.
        DB::table('mods')->where('id', $mod->id)->update(['endorsements_count' => 0]);

        $this->service->revoke($user, $mod);

        expect($mod->refresh()->endorsements_count)->toBe(0);
    });

    it('reports the endorsement as not standing once withdrawn', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);

        expect($this->service->isEndorsedBy($user, $mod))->toBeTrue();

        $this->service->revoke($user, $mod);

        expect($this->service->isEndorsedBy($user, $mod))->toBeFalse()
            ->and(ModEndorsement::query()->active()->count())->toBe(0)
            ->and(ModEndorsement::query()->count())->toBe(1);
    });
});

describe('the permanent anchor', function (): void {
    it('reuses the original endorsed_at when a withdrawn endorsement is revived', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);
        $anchor = ModEndorsement::query()->sole()->endorsed_at;

        $this->travel(10)->days();

        $this->service->revoke($user, $mod);
        expect($this->service->endorse($user, $mod))->toBeTrue();

        $endorsement = ModEndorsement::query()->sole();

        expect($endorsement->endorsed_at->toIso8601String())->toBe($anchor->toIso8601String())
            ->and($endorsement->endorsed_at->toIso8601String())->not->toBe(now()->toIso8601String())
            ->and($endorsement->revoked_at)->toBeNull()
            ->and(ModEndorsement::query()->count())->toBe(1)
            ->and($mod->refresh()->endorsements_count)->toBe(1);
    });

    it('holds across a gap long enough to have left every leaderboard window', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);
        $anchor = ModEndorsement::query()->sole()->endorsed_at;

        $this->travel(400)->days();

        $this->service->revoke($user, $mod);
        $this->service->endorse($user, $mod);

        $endorsement = ModEndorsement::query()->sole();

        expect($endorsement->endorsed_at->toIso8601String())->toBe($anchor->toIso8601String())
            ->and($endorsement->endorsed_at->lessThan(now()->subDays(365)))->toBeTrue();
    });

    it('survives repeated cycling, so a mod cannot be pushed back into the seven day window', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();

        $this->service->endorse($user, $mod);
        $anchor = ModEndorsement::query()->sole()->endorsed_at;

        for ($cycle = 0; $cycle < 5; $cycle++) {
            $this->travel(20)->days();
            $this->service->revoke($user, $mod);
            $this->travel(20)->days();
            $this->service->endorse($user, $mod);
        }

        expect(ModEndorsement::query()->sole()->endorsed_at->toIso8601String())->toBe($anchor->toIso8601String())
            ->and(ModEndorsement::query()->where('endorsed_at', '>=', now()->subDays(7))->count())->toBe(0)
            ->and($mod->refresh()->endorsements_count)->toBe(1);
    });
});

it('does not throw or double count when two endorsements race', function (): void {
    $user = User::factory()->create();
    $mod = Mod::factory()->create();

    // Two concurrent requests, each holding its own Mod instance and neither having seen the other's write. The
    // service uses insertOrIgnore rather than catching a unique violation precisely so the loser does not throw:
    // on PostgreSQL a failed statement aborts the surrounding transaction, which here is the suite's own wrapping
    // transaction, and every assertion after it would then fail for the wrong reason.
    $first = Mod::query()->findOrFail($mod->id);
    $second = Mod::query()->findOrFail($mod->id);

    expect(fn (): bool => $this->service->endorse($user, $first))->not->toThrow(Throwable::class)
        ->and(fn (): bool => $this->service->endorse($user, $second))->not->toThrow(Throwable::class)
        ->and(ModEndorsement::query()->count())->toBe(1)
        ->and($mod->refresh()->endorsements_count)->toBe(1);
});

it('keeps each user and mod pairing on its own counter', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $mod = Mod::factory()->create();
    $otherMod = Mod::factory()->create();

    $this->service->endorse($user, $mod);
    $this->service->endorse($otherUser, $mod);
    $this->service->endorse($user, $otherMod);
    $this->service->revoke($otherUser, $mod);

    expect($mod->refresh()->endorsements_count)->toBe(1)
        ->and($otherMod->refresh()->endorsements_count)->toBe(1)
        ->and(ModEndorsement::query()->count())->toBe(3)
        ->and(ModEndorsement::query()->active()->count())->toBe(2);
});
