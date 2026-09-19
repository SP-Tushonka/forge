<?php

declare(strict_types=1);

use App\Enums\ReportReason;
use App\Models\Comment;
use App\Models\ModIssue;
use App\Models\ModIssueBan;
use App\Models\User;
use App\Policies\CommentPolicy;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Notification::fake();

    $this->policy = new CommentPolicy;
    $this->mod = modWithIssues();
    $this->owner = $this->mod->owner;
    $this->author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($this->author);
    $this->user = User::factory()->create();
    $this->moderator = User::factory()->moderator()->create();
    $this->issue = ModIssue::factory()->for($this->mod)->create();
});

function commentOnIssue(ModIssue $issue, User $author): Comment
{
    return Comment::factory()->create([
        'commentable_type' => ModIssue::class,
        'commentable_id' => $issue->id,
        'user_id' => $author->id,
    ]);
}

describe('create', function (): void {
    it('lets members comment on an open issue', function (): void {
        expect($this->policy->create($this->user, $this->issue))->toBeTrue();
    });

    it('keeps a locked issue to managers and staff', function (): void {
        $this->issue->update(['locked_at' => now()]);

        expect($this->policy->create($this->user, $this->issue))->toBeFalse()
            ->and($this->policy->create($this->author, $this->issue))->toBeTrue()
            ->and($this->policy->create($this->moderator, $this->issue))->toBeTrue();
    });

    it('keeps out issue-banned and blocked members', function (): void {
        ModIssueBan::factory()->for($this->mod)->for($this->user, 'user')->create();
        $blocked = User::factory()->create();
        $blocked->block($this->owner);

        expect($this->policy->create($this->user, $this->issue))->toBeFalse()
            ->and($this->policy->create($blocked, $this->issue))->toBeFalse();
    });

    it('refuses comments on a deleted issue, even from managers', function (): void {
        $this->issue->delete();

        expect($this->policy->create($this->owner, $this->issue))->toBeFalse();
    });
});

describe('author actions', function (): void {
    it('gives the mod managers author actions on issue comments', function (): void {
        $comment = commentOnIssue($this->issue, $this->user);

        expect($this->policy->viewActions($this->author, $comment))->toBeTrue()
            ->and($this->policy->modOwnerSoftDelete($this->author, $comment))->toBeTrue()
            ->and($this->policy->pin($this->owner, $comment))->toBeTrue()
            ->and($this->policy->viewActions($this->user, $comment))->toBeFalse();
    });

    it('offers Ban from issues on ordinary members comments only', function (): void {
        $memberComment = commentOnIssue($this->issue, $this->user);
        $staffComment = commentOnIssue($this->issue, $this->moderator);

        expect($this->policy->banFromIssues($this->owner, $memberComment))->toBeTrue()
            ->and($this->policy->banFromIssues($this->owner, $staffComment))->toBeFalse()
            ->and($this->policy->banFromIssues($this->user, $memberComment))->toBeFalse();

        ModIssueBan::factory()->for($this->mod)->for($this->user, 'user')->create();

        expect($this->policy->banFromIssues($this->owner, $memberComment))->toBeFalse();
    });
});

it('hides comments on a deleted issue from the public', function (): void {
    $comment = commentOnIssue($this->issue, $this->user);
    $this->issue->delete();

    expect($this->policy->view(null, $comment->fresh()))->toBeFalse();
});

it('mutes the issue from the email unsubscribe link', function (): void {
    $this->issue->subscribeUser($this->user);

    $this->get(URL::signedRoute('comment.unsubscribe', [
        'user' => $this->user->id,
        'commentable_type' => ModIssue::class,
        'commentable_id' => $this->issue->id,
    ]))->assertOk();

    expect($this->user->hasMuted($this->issue))->toBeTrue()
        ->and($this->issue->isUserSubscribed($this->user))->toBeFalse();
});

it('lets a member report an issue', function (): void {
    Livewire::actingAs($this->user)
        ->test('report-component', ['reportableId' => $this->issue->id, 'reportableType' => ModIssue::class])
        ->set('reason', ReportReason::OTHER)
        ->set('context', 'Advertising another site.')
        ->call('submit')
        ->assertHasNoErrors();

    expect($this->issue->reports()->count())->toBe(1);
});
