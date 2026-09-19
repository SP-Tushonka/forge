<?php

declare(strict_types=1);

use App\Actions\ModIssues\ChangeModIssueStatus;
use App\Enums\ModIssueEventType;
use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use App\Models\User;
use App\Notifications\ModIssueStatusChangedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();

    $this->mod = modWithIssues();
    $this->owner = $this->mod->owner;
    $this->issue = ModIssue::factory()->for($this->mod)->create();
    $this->action = resolve(ChangeModIssueStatus::class);
});

it('stamps who closed the issue and clears it again on reopen', function (): void {
    $this->action->execute($this->issue, ModIssueStatus::Completed, $this->owner);

    expect($this->issue->closed_by)->toBe($this->owner->id)
        ->and($this->issue->closed_at)->not->toBeNull();

    $this->action->execute($this->issue, ModIssueStatus::New, $this->issue->user);

    expect($this->issue->closed_at)->toBeNull()
        ->and($this->issue->closed_by)->toBeNull()
        ->and($this->issue->events()->pluck('type')->all())
        ->toBe([ModIssueEventType::StatusChanged, ModIssueEventType::Reopened, ModIssueEventType::StatusChanged]);
});

it('needs another issue on the same mod to mark a duplicate', function (): void {
    $elsewhere = ModIssue::factory()->for(modWithIssues())->create();

    expect(fn () => $this->action->execute($this->issue, ModIssueStatus::Duplicate, $this->owner))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->action->execute($this->issue, ModIssueStatus::Duplicate, $this->owner, $elsewhere))
        ->toThrow(ValidationException::class)
        ->and(fn () => $this->action->execute($this->issue, ModIssueStatus::Duplicate, $this->owner, $this->issue))
        ->toThrow(ValidationException::class);
});

it('links the original and unlinks it when the issue stops being a duplicate', function (): void {
    $original = ModIssue::factory()->for($this->mod)->create();

    $this->action->execute($this->issue, ModIssueStatus::Duplicate, $this->owner, $original);
    expect($this->issue->duplicate_of_id)->toBe($original->id);

    $this->action->execute($this->issue, ModIssueStatus::New, $this->owner);
    expect($this->issue->duplicate_of_id)->toBeNull();
});

it('notifies subscribers other than the actor', function (): void {
    $follower = User::factory()->create();
    $this->issue->subscribeUser($follower);
    $this->issue->subscribeUser($this->owner);

    $this->action->execute($this->issue, ModIssueStatus::InProgress, $this->owner);

    Notification::assertSentTo($follower, ModIssueStatusChangedNotification::class);
    Notification::assertNotSentTo($this->owner, ModIssueStatusChangedNotification::class);
});

it('logs an automatic change without a name and sends nothing', function (): void {
    $this->issue->update(['status' => ModIssueStatus::NeedsInfo]);
    $this->issue->subscribeUser(User::factory()->create());

    $this->action->execute($this->issue, ModIssueStatus::New, null);

    expect($this->issue->events()->sole()->user_id)->toBeNull();
    Notification::assertNothingSent();
});

it('does nothing when the status does not change', function (): void {
    $this->action->execute($this->issue, ModIssueStatus::New, $this->owner);

    expect($this->issue->events()->count())->toBe(0);
});
