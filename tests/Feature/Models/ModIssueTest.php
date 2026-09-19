<?php

declare(strict_types=1);

use App\Enums\IssueNotificationLevel;
use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use App\Models\ModIssueBan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
});

describe('numbering', function (): void {
    it('numbers factory issues per mod', function (): void {
        $mod = modWithIssues();
        $other = modWithIssues();

        $first = ModIssue::factory()->for($mod)->create();
        $second = ModIssue::factory()->for($mod)->create();
        $elsewhere = ModIssue::factory()->for($other)->create();

        expect([$first->number, $second->number, $elsewhere->number])->toBe([1, 2, 1])
            ->and($mod->fresh()?->last_issue_number)->toBe(2);
    });
});

describe('comments', function (): void {
    it('accepts comments on an open issue of a published mod with issues on', function (): void {
        $issue = ModIssue::factory()->for(modWithIssues())->create();

        expect($issue->canReceiveComments())->toBeTrue();
    });

    it('refuses comments once the issue is locked, deleted, switched off or unpublished', function (string $change): void {
        $issue = ModIssue::factory()->for(modWithIssues())->create();

        match ($change) {
            'locked' => $issue->update(['locked_at' => now()]),
            'deleted' => $issue->delete(),
            'issues off' => $issue->mod->update(['issues_enabled' => false]),
            'unpublished' => $issue->mod->update(['published_at' => null]),
        };

        expect($issue->canReceiveComments())->toBeFalse();
    })->with(['locked', 'deleted', 'issues off', 'unpublished']);

    it('titles and links itself by number', function (): void {
        $mod = modWithIssues();
        $issue = ModIssue::factory()->for($mod)->create(['title' => 'Crash on load']);

        expect($issue->getTitle())->toBe('#1 Crash on load')
            ->and($issue->url())->toBe(route('mod.issue.show', [$mod->id, $mod->slug, 1]));
    });
});

describe('scopes', function (): void {
    it('splits a mod issues into open and closed', function (): void {
        $mod = modWithIssues();
        $open = ModIssue::factory()->for($mod)->create(['status' => ModIssueStatus::InProgress]);
        $closed = ModIssue::factory()->for($mod)->create(['status' => ModIssueStatus::Duplicate]);

        expect($mod->issues()->open()->pluck('id')->all())->toBe([$open->id])
            ->and($mod->issues()->closed()->pluck('id')->all())->toBe([$closed->id]);
    });
});

describe('notification opt-outs', function (): void {
    it('mutes on unsubscribe and clears the mute on subscribe', function (): void {
        $issue = ModIssue::factory()->for(modWithIssues())->create();
        $user = User::factory()->create();

        $issue->subscribeUser($user);
        $issue->unsubscribeUser($user);

        expect($user->hasMuted($issue))->toBeTrue()
            ->and($issue->isUserSubscribed($user))->toBeFalse();

        $issue->subscribeUser($user);

        expect($user->hasMuted($issue))->toBeFalse()
            ->and($issue->isUserSubscribed($user))->toBeTrue();
    });

    it('leaves out subscribers who muted the issue or its mod, or switched issue notifications off', function (): void {
        $issue = ModIssue::factory()->for(modWithIssues())->create();
        [$listening, $mutedIssue, $mutedMod, $off] = User::factory()->count(4)->create()->all();

        foreach ([$listening, $mutedIssue, $mutedMod, $off] as $user) {
            $issue->subscribeUser($user);
        }

        $mutedIssue->mute($issue);
        $mutedMod->mute($issue->mod);
        $off->update(['issue_notifications' => IssueNotificationLevel::Off]);

        expect($issue->getSubscribers()->pluck('id')->all())->toBe([$listening->id])
            ->and($issue->allowsNotificationTo($mutedMod))->toBeFalse()
            ->and($issue->allowsNotificationTo($listening))->toBeTrue();
    });

    it('does not auto-subscribe someone who muted the issue or its mod', function (): void {
        $issue = ModIssue::factory()->for(modWithIssues())->create();
        $mutedMod = User::factory()->create();
        $mutedMod->mute($issue->mod);

        $issue->subscribeUnlessMuted($mutedMod);

        expect($issue->isUserSubscribed($mutedMod))->toBeFalse();
    });
});

describe('mod helpers', function (): void {
    it('treats only unexpired bans as active', function (): void {
        $mod = modWithIssues();
        $banned = User::factory()->create();
        $expired = User::factory()->create();
        ModIssueBan::factory()->for($mod)->for($banned, 'user')->create();
        ModIssueBan::factory()->for($mod)->for($expired, 'user')->create(['expires_at' => now()->subMinute()]);

        expect($mod->isIssueBanned($banned))->toBeTrue()
            ->and($mod->isIssueBanned($expired))->toBeFalse();
    });

    it('lists the owner and co-authors as managers', function (): void {
        $mod = modWithIssues();
        $author = User::factory()->create();
        $mod->additionalAuthors()->attach($author);

        expect($mod->fresh()?->managers()->pluck('id')->sort()->values()->all())
            ->toBe(collect([$mod->owner_id, $author->id])->sort()->values()->all());
    });

    it('defaults the issue notification level to all', function (): void {
        expect(User::factory()->make()->issueNotificationLevel())->toBe(IssueNotificationLevel::All);
    });
});
