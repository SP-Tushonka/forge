<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use App\Models\ModIssueBan;
use App\Models\User;
use App\Policies\ModIssuePolicy;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();

    $this->policy = new ModIssuePolicy;
    $this->mod = modWithIssues();
    $this->owner = $this->mod->owner;
    $this->author = User::factory()->create();
    $this->mod->additionalAuthors()->attach($this->author);
    $this->reporter = User::factory()->create();
    $this->user = User::factory()->create();
    $this->moderator = User::factory()->moderator()->create();
    $this->issue = ModIssue::factory()->for($this->mod)->for($this->reporter, 'user')->create();
});

describe('viewAny', function (): void {
    it('shows the tab to everyone while issues are on', function (): void {
        expect($this->policy->viewAny(null, $this->mod))->toBeTrue();
    });

    it('limits the tab to managers and staff once issues are off', function (): void {
        $this->mod->update(['issues_enabled' => false]);

        expect($this->policy->viewAny(null, $this->mod))->toBeFalse()
            ->and($this->policy->viewAny($this->user, $this->mod))->toBeFalse()
            ->and($this->policy->viewAny($this->author, $this->mod))->toBeTrue()
            ->and($this->policy->viewAny($this->moderator, $this->mod))->toBeTrue();
    });
});

describe('view', function (): void {
    it('hides a deleted issue from everyone but managers and staff', function (): void {
        $this->issue->delete();

        expect($this->policy->view(null, $this->issue))->toBeFalse()
            ->and($this->policy->view($this->reporter, $this->issue))->toBeFalse()
            ->and($this->policy->view($this->owner, $this->issue))->toBeTrue()
            ->and($this->policy->view($this->moderator, $this->issue))->toBeTrue();
    });
});

describe('create', function (): void {
    it('lets a verified member open an issue', function (): void {
        expect($this->policy->create($this->user, $this->mod)->allowed())->toBeTrue();
    });

    it('refuses unverified, issue-banned and blocked members', function (): void {
        $unverified = User::factory()->unverified()->create();
        ModIssueBan::factory()->for($this->mod)->for($this->user, 'user')->create();
        $blocked = User::factory()->create();
        $this->owner->block($blocked);

        expect($this->policy->create($unverified, $this->mod)->allowed())->toBeFalse()
            ->and($this->policy->create($this->user, $this->mod)->allowed())->toBeFalse()
            ->and($this->policy->create($blocked, $this->mod)->allowed())->toBeFalse();
    });

    it('never applies issue bans to staff', function (): void {
        ModIssueBan::factory()->for($this->mod)->for($this->moderator, 'user')->create();

        expect($this->policy->create($this->moderator, $this->mod)->allowed())->toBeTrue();
    });

    it('closes the mod to members when issues are off, but not to its managers', function (): void {
        $this->mod->update(['issues_enabled' => false]);

        expect($this->policy->create($this->user, $this->mod)->allowed())->toBeFalse()
            ->and($this->policy->create($this->owner, $this->mod)->allowed())->toBeTrue();
    });

    it('lets managers log issues on an unpublished mod', function (): void {
        $this->mod->update(['published_at' => null]);

        expect($this->policy->create($this->author, $this->mod)->allowed())->toBeTrue()
            ->and($this->policy->create($this->user, $this->mod)->allowed())->toBeFalse();
    });
});

describe('update', function (): void {
    it('lets the reporter edit while the issue is open', function (): void {
        expect($this->policy->update($this->reporter, $this->issue))->toBeTrue()
            ->and($this->policy->update($this->user, $this->issue))->toBeFalse();
    });

    it('stops the reporter editing a closed issue, but not the managers', function (): void {
        $this->issue->update(['status' => ModIssueStatus::Completed]);

        expect($this->policy->update($this->reporter, $this->issue))->toBeFalse()
            ->and($this->policy->update($this->author, $this->issue))->toBeTrue();
    });
});

describe('close and reopen', function (): void {
    it('lets the reporter close their own open issue', function (): void {
        expect($this->policy->close($this->reporter, $this->issue))->toBeTrue()
            ->and($this->policy->close($this->user, $this->issue))->toBeFalse();
    });

    it('lets the reporter reopen only what they closed themselves', function (): void {
        $this->issue->update(['status' => ModIssueStatus::Closed, 'closed_at' => now(), 'closed_by' => $this->reporter->id]);

        expect($this->policy->reopen($this->reporter, $this->issue))->toBeTrue();

        $this->issue->update(['closed_by' => $this->owner->id]);

        expect($this->policy->reopen($this->reporter, $this->issue))->toBeFalse()
            ->and($this->policy->reopen($this->owner, $this->issue))->toBeTrue();
    });
});

describe('delete and restore', function (): void {
    it('lets managers delete, and restore only their own deletions', function (): void {
        expect($this->policy->delete($this->author, $this->issue))->toBeTrue()
            ->and($this->policy->delete($this->reporter, $this->issue))->toBeFalse();

        $this->issue->update(['deleted_by' => $this->moderator->id]);
        $this->issue->delete();

        expect($this->policy->restore($this->author, $this->issue))->toBeFalse()
            ->and($this->policy->restore($this->moderator, $this->issue))->toBeTrue();

        $this->issue->update(['deleted_by' => $this->author->id]);

        expect($this->policy->restore($this->author, $this->issue))->toBeTrue();
    });
});

describe('ban', function (): void {
    it('lets managers ban ordinary members only', function (): void {
        expect($this->policy->ban($this->author, $this->mod, $this->user))->toBeTrue()
            ->and($this->policy->ban($this->author, $this->mod, $this->owner))->toBeFalse()
            ->and($this->policy->ban($this->author, $this->mod, $this->moderator))->toBeFalse()
            ->and($this->policy->ban($this->author, $this->mod, $this->author))->toBeFalse()
            ->and($this->policy->ban($this->user, $this->mod, $this->reporter))->toBeFalse();
    });
});

describe('react and report', function (): void {
    it('stops reporters reacting to their own issue and banned members reacting at all', function (): void {
        ModIssueBan::factory()->for($this->mod)->for($this->user, 'user')->create();

        expect($this->policy->react($this->reporter, $this->issue)->allowed())->toBeFalse()
            ->and($this->policy->react($this->user, $this->issue)->allowed())->toBeFalse()
            ->and($this->policy->react($this->owner, $this->issue)->allowed())->toBeTrue();
    });

    it('lets members report an issue, but not their own and not as staff', function (): void {
        expect($this->policy->report($this->user, $this->issue))->toBeTrue()
            ->and($this->policy->report($this->reporter, $this->issue))->toBeFalse()
            ->and($this->policy->report($this->moderator, $this->issue))->toBeFalse();
    });
});
