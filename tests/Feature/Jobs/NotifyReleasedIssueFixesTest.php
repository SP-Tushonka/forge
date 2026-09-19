<?php

declare(strict_types=1);

use App\Enums\ModIssueEventType;
use App\Enums\ModIssueStatus;
use App\Jobs\NotifyReleasedIssueFixes;
use App\Models\ModIssue;
use App\Models\ModVersion;
use App\Models\User;
use App\Notifications\ModIssueFixReleasedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();

    $this->mod = modWithIssues();
    $this->follower = User::factory()->create();
    $this->issue = ModIssue::factory()->for($this->mod)->create([
        'status' => ModIssueStatus::Completed,
        'fixed_version' => '1.3.0',
    ]);
    $this->issue->subscribeUser($this->follower);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function shipVersion(object $test, array $attributes = []): ModVersion
{
    return ModVersion::factory()->recycle($test->mod)->create([
        'version' => '1.3.0',
        'spt_version_constraint' => '',
        'published_at' => now()->subMinute(),
        'disabled' => false,
        ...$attributes,
    ]);
}

it('tells followers once the promised version is public', function (): void {
    shipVersion($this);

    new NotifyReleasedIssueFixes()->handle();

    Notification::assertSentTo($this->follower, ModIssueFixReleasedNotification::class);
    expect($this->issue->fresh()?->fix_notified_at)->not->toBeNull()
        ->and($this->issue->events()->sole()->type)->toBe(ModIssueEventType::FixReleased);
});

it('sends the notice only once', function (): void {
    shipVersion($this);

    new NotifyReleasedIssueFixes()->handle();
    new NotifyReleasedIssueFixes()->handle();

    Notification::assertSentToTimes($this->follower, ModIssueFixReleasedNotification::class, 1);
});

it('waits for a scheduled version to go live', function (): void {
    shipVersion($this, ['published_at' => now()->addHour()]);

    new NotifyReleasedIssueFixes()->handle();
    Notification::assertNothingSent();

    $this->travel(2)->hours();
    new NotifyReleasedIssueFixes()->handle();

    Notification::assertSentTo($this->follower, ModIssueFixReleasedNotification::class);
});

it('ignores disabled versions and issues that are not completed', function (): void {
    shipVersion($this, ['disabled' => true]);
    $open = ModIssue::factory()->for($this->mod)->create(['fixed_version' => '1.0.0']);
    $open->subscribeUser($this->follower);

    new NotifyReleasedIssueFixes()->handle();

    Notification::assertNothingSent();
});

it('catches an issue completed after its version already shipped', function (): void {
    $late = ModIssue::factory()->for($this->mod)->create(['fixed_version' => '1.0.0']);
    $late->subscribeUser($this->follower);
    $late->update(['status' => ModIssueStatus::Completed]);

    new NotifyReleasedIssueFixes()->handle();

    Notification::assertSentTo(
        $this->follower,
        ModIssueFixReleasedNotification::class,
        fn (ModIssueFixReleasedNotification $notification): bool => $notification->issue->is($late),
    );
});
