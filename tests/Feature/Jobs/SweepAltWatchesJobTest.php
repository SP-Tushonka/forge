<?php

declare(strict_types=1);

use App\Enums\AltIndicatorType;
use App\Jobs\SweepAltWatchesJob;
use App\Models\AltWatch;
use App\Models\AltWatchIndicator;
use App\Models\AltWatchMatch;
use App\Models\Comment;
use App\Models\User;
use App\Models\UserDevice;
use App\Notifications\AltWatchMatchedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<string, mixed>  $overrides
 */
function sweepTrackEvent(int $visitorId, string $ip, array $overrides = []): void
{
    DB::table('tracking_events')->insert(array_merge([
        'event_name' => 'login',
        'is_moderation_action' => false,
        'ip' => $ip,
        'visitor_type' => User::class,
        'visitor_id' => $visitorId,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function runAltSweep(): void
{
    SweepAltWatchesJob::dispatchSync();
}

function watchFor(AltIndicatorType $type, string $value, ?AltWatch $watch = null): AltWatch
{
    $watch ??= AltWatch::factory()->create();
    AltWatchIndicator::factory()->for($watch, 'watch')->create(['type' => $type, 'value' => $value]);

    return $watch;
}

beforeEach(fn () => Notification::fake());

it('records new matches and alerts every admin once per watch, without raw values in the email', function (): void {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create();
    $watched = User::factory()->create(['name' => 'Evader']);
    $watch = watchFor(AltIndicatorType::IpRange, '84.12.3.0/24', AltWatch::factory()->for($watched, 'watchedUser')->create());
    runAltSweep();

    $first = User::factory()->create(['name' => 'FreshAlt']);
    $second = User::factory()->create(['name' => 'OtherAlt']);
    sweepTrackEvent($first->id, '84.12.3.10');
    sweepTrackEvent($second->id, '84.12.3.11');
    sweepTrackEvent($watched->id, '84.12.3.12');
    runAltSweep();

    expect($watch->matches()->pluck('user_id')->all())->toEqualCanonicalizing([$first->id, $second->id])
        ->and($watch->matches()->where('baseline', false)->count())->toBe(2);
    Notification::assertSentToTimes($admin, AltWatchMatchedNotification::class, 1);
    Notification::assertSentTo($admin, AltWatchMatchedNotification::class, function (AltWatchMatchedNotification $notification) use ($admin): bool {
        $mail = (string) $notification->toMail($admin)->render();

        return count($notification->accounts) === 2
            && $notification->kinds === ['IP range']
            && ! str_contains($mail, '84.12.3');
    });
    Notification::assertNotSentTo($moderator, AltWatchMatchedNotification::class);
});

it('does not alert twice for the same account', function (): void {
    User::factory()->admin()->create();
    $watch = watchFor(AltIndicatorType::Ip, '84.12.3.10');
    runAltSweep();

    $alt = User::factory()->create();
    sweepTrackEvent($alt->id, '84.12.3.10');
    runAltSweep();
    $this->travel(10)->minutes();
    sweepTrackEvent($alt->id, '84.12.3.10');
    runAltSweep();

    expect($watch->matches()->sole()->last_matched_at->toDateTimeString())->toBe(now()->toDateTimeString());
    Notification::assertSentTimes(AltWatchMatchedNotification::class, 1);
});

it('never alerts for an account recorded as already matching when the watch was saved', function (): void {
    User::factory()->admin()->create();
    $watch = watchFor(AltIndicatorType::Ip, '84.12.3.10');
    $known = User::factory()->create();
    AltWatchMatch::factory()->for($watch, 'watch')->for($known)->baseline()->create();
    runAltSweep();

    sweepTrackEvent($known->id, '84.12.3.10');
    runAltSweep();

    expect($watch->matches()->sole()->baseline)->toBeTrue();
    Notification::assertNothingSent();
});

it('ignores ended and expired watches', function (): void {
    User::factory()->admin()->create();
    watchFor(AltIndicatorType::Ip, '84.12.3.10', AltWatch::factory()->ended()->create());
    watchFor(AltIndicatorType::Ip, '84.12.3.10', AltWatch::factory()->expired()->create());
    runAltSweep();

    sweepTrackEvent(User::factory()->create()->id, '84.12.3.10');
    runAltSweep();

    expect(AltWatchMatch::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('moves its cursors forward even when nothing is being watched', function (): void {
    runAltSweep();
    sweepTrackEvent(User::factory()->create()->id, '84.12.3.10');
    $latest = DB::table('tracking_events')->max('id');

    runAltSweep();

    expect(DB::table('alt_monitor_cursors')->where('source', 'tracking_events')->value('position'))->toBe((string) $latest);
});

it('picks up a tracking event that arrives late with an old timestamp', function (): void {
    User::factory()->admin()->create();
    $watch = watchFor(AltIndicatorType::Ip, '84.12.3.10');
    $alt = User::factory()->create();
    runAltSweep();
    $this->travel(1)->hour();
    runAltSweep();

    sweepTrackEvent($alt->id, '84.12.3.10', ['created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
    runAltSweep();

    expect($watch->matches()->sole()->user_id)->toBe($alt->id);
});

it('works off a backlog larger than one run reads over several runs', function (): void {
    User::factory()->admin()->create();
    $watch = watchFor(AltIndicatorType::Ip, '84.12.3.10');
    $alt = User::factory()->create();
    runAltSweep();
    // Past the timestamp cursors, so only the tracking event can bring the account in.
    $this->travel(2)->minutes();
    runAltSweep();

    $cap = new ReflectionClassConstant(SweepAltWatchesJob::class, 'MAX_IDS_PER_RUN')->getValue();
    $cursor = (int) DB::table('alt_monitor_cursors')->where('source', 'tracking_events')->value('position');
    sweepTrackEvent($alt->id, '84.12.3.10', ['id' => $cursor + $cap + 1]);

    runAltSweep();
    expect($watch->matches()->count())->toBe(0)
        ->and(DB::table('alt_monitor_cursors')->where('source', 'tracking_events')->value('position'))->toBe((string) ($cursor + $cap));

    runAltSweep();
    expect($watch->matches()->sole()->user_id)->toBe($alt->id);
});

it('matches on a comment posted from a watched address', function (): void {
    User::factory()->admin()->create();
    $watch = watchFor(AltIndicatorType::Ip, '84.12.3.10');
    $alt = User::factory()->create();
    runAltSweep();
    $this->travel(2)->minutes();
    runAltSweep();

    Comment::factory()->createQuietly(['user_id' => $alt->id, 'user_ip' => '84.12.3.10']);
    runAltSweep();

    expect($watch->matches()->sole()->user_id)->toBe($alt->id);
});

it('matches "all" watches on indicators seen in separate events', function (): void {
    User::factory()->admin()->create();
    $watch = watchFor(AltIndicatorType::Device, str_repeat('h', 64), AltWatch::factory()->matchingAll()->create());
    watchFor(AltIndicatorType::IpRange, '84.12.3.0/24', $watch);
    runAltSweep();

    $alt = User::factory()->create();
    UserDevice::factory()->for($alt)->create(['device_hash' => str_repeat('h', 64), 'last_ip' => '198.51.100.1']);
    runAltSweep();
    expect($watch->matches()->count())->toBe(0);

    sweepTrackEvent($alt->id, '84.12.3.10');
    runAltSweep();

    expect($watch->matches()->sole()->user_id)->toBe($alt->id);
});
