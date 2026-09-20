<?php

declare(strict_types=1);

use App\Actions\ModIssues\CreateModIssue;
use App\Enums\IssueNotificationLevel;
use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Models\User;
use App\Notifications\NewModIssueNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();

    $this->mod = modWithIssues();
    $this->author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($this->author);
    $this->reporter = User::factory()->create();
    $this->versionId = $this->mod->versions()->value('id');
});

function openIssue(object $test, ?ModIssueType $type = null): App\Models\ModIssue
{
    return resolve(CreateModIssue::class)->execute(
        $test->reporter,
        $test->mod,
        $type ?? ModIssueType::Bug,
        'Crash on load',
        'It crashes as soon as the raid starts.',
        $test->versionId,
    );
}

it('numbers issues per mod and never reuses a deleted number', function (): void {
    $first = openIssue($this);
    $first->delete();
    $second = openIssue($this);

    expect($first->number)->toBe(1)
        ->and($second->number)->toBe(2)
        ->and($second->status)->toBe(ModIssueStatus::New);
});

it('keeps the affected version for bugs and drops it for feature requests', function (): void {
    expect(openIssue($this)->affected_mod_version_id)->toBe($this->versionId)
        ->and(openIssue($this, ModIssueType::Feature)->affected_mod_version_id)->toBeNull();
});

it('subscribes the reporter and managers, and notifies the managers', function (): void {
    $issue = openIssue($this);

    expect($issue->isUserSubscribed($this->reporter))->toBeTrue()
        ->and($issue->isUserSubscribed($this->author))->toBeTrue()
        ->and($issue->isUserSubscribed($this->mod->owner))->toBeTrue();

    Notification::assertSentTo([$this->mod->owner, $this->author], NewModIssueNotification::class);
    Notification::assertNotSentTo($this->reporter, NewModIssueNotification::class);
});

it('refuses a type the mod does not accept', function (): void {
    $this->mod->update(['disabled_issue_types' => [ModIssueType::Question->value]]);

    expect(fn (): App\Models\ModIssue => openIssue($this, ModIssueType::Question))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('leaves out managers who muted the mod or switched issue notifications off', function (): void {
    $this->author->mute($this->mod);
    $this->mod->owner->update(['issue_notifications' => IssueNotificationLevel::Off]);

    $issue = openIssue($this);

    expect($issue->isUserSubscribed($this->author))->toBeFalse();
    Notification::assertNothingSent();
});
