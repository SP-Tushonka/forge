<?php

declare(strict_types=1);

use App\Actions\AltMonitoring\SaveAltWatch;
use App\Enums\AltIndicatorType;
use App\Enums\AltWatchMatchMode;
use App\Exceptions\AltWatchTooBroadException;
use App\Models\AltWatch;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\DataTransferObjects\AltIndicator;
use App\Support\DataTransferObjects\AltWatchDraft;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function newWatchOn(User $watched, User $admin): AltWatch
{
    return new AltWatch([
        'watched_user_id' => $watched->id,
        'watched_user_name' => $watched->name,
        'created_by' => $admin->id,
        'reason' => 'Suspected ban evasion.',
    ]);
}

beforeEach(fn () => Notification::fake());

it('saves the watch and records accounts that already match as baseline, without alerting', function (): void {
    $admin = User::factory()->admin()->create();
    $watched = User::factory()->create();
    $existing = User::factory()->create();
    UserDevice::factory()->for($watched)->create(['device_hash' => str_repeat('f', 64)]);
    UserDevice::factory()->for($existing)->create(['device_hash' => str_repeat('f', 64)]);
    $draft = new AltWatchDraft($watched->id, AltWatchMatchMode::Any, [new AltIndicator(AltIndicatorType::Device, str_repeat('f', 64), 'Chrome on Windows')]);

    $watch = resolve(SaveAltWatch::class)->handle(newWatchOn($watched, $admin), $draft, 90);

    $match = $watch->matches()->sole();
    expect($watch->expires_at->toDateTimeString())->toBe(now()->addDays(90)->toDateTimeString())
        ->and($watch->match_mode)->toBe(AltWatchMatchMode::Any)
        ->and($match->user_id)->toBe($existing->id)
        ->and($match->baseline)->toBeTrue()
        ->and($match->matched_indicator_ids)->toBe([$watch->indicators()->sole()->id]);
    Notification::assertNothingSent();
});

it('refuses a watch that matches more than 100 accounts', function (): void {
    $admin = User::factory()->admin()->create();
    $watched = User::factory()->create(['email' => 'watched@crowd.test']);
    User::factory()->count(101)->sequence(fn (Sequence $sequence): array => ['email' => 'member'.$sequence->index.'@crowd.test'])->create();
    $draft = new AltWatchDraft($watched->id, AltWatchMatchMode::Any, [new AltIndicator(AltIndicatorType::EmailDomain, 'crowd.test', 'crowd.test')]);

    expect(fn () => resolve(SaveAltWatch::class)->handle(newWatchOn($watched, $admin), $draft, 90))
        ->toThrow(AltWatchTooBroadException::class);
    expect(AltWatch::query()->count())->toBe(0);
});

it('saves a watch that matches an account deleted before its activity was pruned, recording only existing accounts', function (): void {
    $admin = User::factory()->admin()->create();
    $watched = User::factory()->create();
    $deleted = User::factory()->create();
    $existing = User::factory()->create();

    foreach ([$deleted, $existing] as $user) {
        DB::table('tracking_events')->insert([
            'event_name' => 'login',
            'is_moderation_action' => false,
            'ip' => '84.12.3.77',
            'visitor_type' => User::class,
            'visitor_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    User::query()->whereKey($deleted->id)->delete();
    $draft = new AltWatchDraft($watched->id, AltWatchMatchMode::Any, [new AltIndicator(AltIndicatorType::Ip, '84.12.3.77', '84.12.3.77')]);

    $watch = resolve(SaveAltWatch::class)->handle(newWatchOn($watched, $admin), $draft, 90);

    expect($watch->exists)->toBeTrue()
        ->and($watch->matches()->pluck('user_id')->all())->toBe([$existing->id]);
});

it('keeps unchanged indicators on edit, and records accounts that match only because of the edit as baseline', function (): void {
    $admin = User::factory()->admin()->create();
    $watched = User::factory()->create();
    $later = User::factory()->create();
    DB::table('tracking_events')->insert([
        'event_name' => 'login',
        'is_moderation_action' => false,
        'ip' => '84.12.3.77',
        'visitor_type' => User::class,
        'visitor_id' => $later->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $device = new AltIndicator(AltIndicatorType::Device, str_repeat('g', 64), 'Device');
    $action = resolve(SaveAltWatch::class);

    $watch = $action->handle(newWatchOn($watched, $admin), new AltWatchDraft($watched->id, AltWatchMatchMode::Any, [$device]), 30);
    $deviceId = $watch->indicators()->sole()->id;
    $expiry = $watch->expires_at->toDateTimeString();

    $this->travel(1)->days();
    $watch = $action->handle($watch, new AltWatchDraft($watched->id, AltWatchMatchMode::Any, [$device, new AltIndicator(AltIndicatorType::IpRange, '84.12.3.0/24', '84.12.3.0/24')]), null);

    expect($watch->indicators()->pluck('id')->all())->toContain($deviceId)
        ->and($watch->indicators()->count())->toBe(2)
        ->and($watch->refresh()->expires_at->toDateTimeString())->toBe($expiry)
        ->and($watch->matches()->sole()->user_id)->toBe($later->id)
        ->and($watch->matches()->sole()->baseline)->toBeTrue();
});
