<?php

declare(strict_types=1);

use App\Enums\IssueNotificationLevel;
use App\Enums\ModIssueStatus;
use App\Jobs\ProcessCommentNotification;
use App\Models\Comment;
use App\Models\ModIssue;
use App\Models\User;
use App\Notifications\CommentReplyNotification;
use App\Notifications\ModIssueFixReleasedNotification;
use App\Notifications\ModIssueStatusChangedNotification;
use App\Notifications\NewCommentNotification;
use App\Notifications\NewModIssueNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->issue = ModIssue::factory()->for(modWithIssues())->create();
});

/**
 * @param  array<string, mixed>  $data
 */
function storedIssueNotification(string $type, array $data): DatabaseNotification
{
    /** @var DatabaseNotification */
    return User::factory()->create()->notifications()->create([
        'id' => fake()->uuid(),
        'type' => $type,
        'data' => $data,
        'read_at' => null,
    ]);
}

it('delivers issue notifications on the channels the recipient allows', function (IssueNotificationLevel $level, array $channels): void {
    $user = User::factory()->create(['issue_notifications' => $level]);

    expect(new NewModIssueNotification($this->issue)->via($user))->toBe($channels)
        ->and(new ModIssueFixReleasedNotification($this->issue, '1.3.0')->via($user))->toBe($channels);
})->with([
    [IssueNotificationLevel::All, ['database', 'mail']],
    [IssueNotificationLevel::Bell, ['database']],
    [IssueNotificationLevel::Off, []],
]);

it('stores and presents a new issue', function (): void {
    $data = new NewModIssueNotification($this->issue)->toArray($this->issue->user);
    $presentation = NewModIssueNotification::presentDatabaseNotification(storedIssueNotification(NewModIssueNotification::class, $data));

    expect($data['issue_url'])->toBe($this->issue->url())
        ->and($presentation->iconName)->toBe('bug-ant')
        ->and($presentation->url)->toBe($this->issue->url());
});

it('presents a status change with both labels', function (): void {
    $actor = User::factory()->create();
    $data = new ModIssueStatusChangedNotification($this->issue, ModIssueStatus::New, ModIssueStatus::InProgress, $actor)->toArray($actor);
    $presentation = ModIssueStatusChangedNotification::presentDatabaseNotification(storedIssueNotification(ModIssueStatusChangedNotification::class, $data));

    expect($presentation->preview)->toBe('New → In progress')
        ->and($presentation->url)->toBe($this->issue->url());
});

it('presents a released fix with its version', function (): void {
    $data = new ModIssueFixReleasedNotification($this->issue, '1.3.0')->toArray($this->issue->user);
    $presentation = ModIssueFixReleasedNotification::presentDatabaseNotification(storedIssueNotification(ModIssueFixReleasedNotification::class, $data));

    expect($data['version'])->toBe('1.3.0')
        ->and($presentation->iconName)->toBe('check-badge');
});

it('emails issue comments only when the issue setting also allows mail', function (): void {
    $comment = Comment::factory()->create(['commentable_type' => ModIssue::class, 'commentable_id' => $this->issue->id]);
    $bellOnly = User::factory()->create(['issue_notifications' => IssueNotificationLevel::Bell, 'email_comment_notifications_enabled' => true]);
    $everything = User::factory()->create(['issue_notifications' => IssueNotificationLevel::All, 'email_comment_notifications_enabled' => true]);

    expect(new NewCommentNotification($comment)->via($bellOnly))->toBe(['database'])
        ->and(new NewCommentNotification($comment)->via($everything))->toBe(['database', 'mail']);
});

it('does not notify a parent author who muted the issue of a reply', function (): void {
    Notification::fake();
    $parentAuthor = User::factory()->create();
    $parent = Comment::factory()->create(['commentable_type' => ModIssue::class, 'commentable_id' => $this->issue->id, 'user_id' => $parentAuthor->id]);
    $reply = Comment::factory()->create([
        'commentable_type' => ModIssue::class,
        'commentable_id' => $this->issue->id,
        'parent_id' => $parent->id,
        'root_id' => $parent->id,
    ]);
    $parentAuthor->mute($this->issue);

    new ProcessCommentNotification($reply)->handle();

    Notification::assertNotSentTo($parentAuthor, CommentReplyNotification::class);
    Notification::assertNotSentTo($parentAuthor, NewCommentNotification::class);
});
