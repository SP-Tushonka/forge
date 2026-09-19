<?php

declare(strict_types=1);

use App\Actions\ModIssues\BanFromModIssues;
use App\Actions\ModIssues\DeleteModIssue;
use App\Actions\ModIssues\EditModIssue;
use App\Actions\ModIssues\LiftModIssueBan;
use App\Actions\ModIssues\RestoreModIssue;
use App\Actions\ModIssues\SetModIssueLock;
use App\Actions\ModIssues\UpdateModIssueDetails;
use App\Enums\ModIssueEventType;
use App\Enums\ModIssueType;
use App\Models\ModIssue;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Queue::fake();

    $this->mod = modWithIssues();
    $this->owner = $this->mod->owner;
    $this->issue = ModIssue::factory()->for($this->mod)->create();
});

describe('details', function (): void {
    it('strips a v prefix, logs the change and re-arms the release notice', function (): void {
        $this->issue->update(['fixed_version' => '1.2.0', 'fix_notified_at' => now()]);

        resolve(UpdateModIssueDetails::class)->execute($this->issue, $this->owner, ModIssueType::Bug, 'v1.3.0');

        expect($this->issue->fixed_version)->toBe('1.3.0')
            ->and($this->issue->fix_notified_at)->toBeNull()
            ->and($this->issue->events()->sole()->type)->toBe(ModIssueEventType::FixedVersionChanged);
    });

    it('rejects a fixed version that is not semver', function (): void {
        expect(fn () => resolve(UpdateModIssueDetails::class)->execute($this->issue, $this->owner, ModIssueType::Bug, 'soon'))
            ->toThrow(ValidationException::class);
    });

    it('logs a type change', function (): void {
        resolve(UpdateModIssueDetails::class)->execute($this->issue, $this->owner, ModIssueType::Feature, null);

        expect($this->issue->type)->toBe(ModIssueType::Feature)
            ->and($this->issue->events()->sole()->to)->toBe('feature');
    });
});

it('locks and unlocks with an event each way', function (): void {
    resolve(SetModIssueLock::class)->execute($this->issue, true, $this->owner);
    expect($this->issue->isLocked())->toBeTrue();

    resolve(SetModIssueLock::class)->execute($this->issue, false, $this->owner);
    expect($this->issue->isLocked())->toBeFalse()
        ->and($this->issue->events()->pluck('type')->all())->toBe([ModIssueEventType::Locked, ModIssueEventType::Unlocked]);
});

it('marks an edited issue as edited', function (): void {
    resolve(EditModIssue::class)->execute($this->issue, 'A clearer title', $this->issue->body);

    expect($this->issue->title)->toBe('A clearer title')
        ->and($this->issue->edited_at)->not->toBeNull();
});

it('soft deletes with the deleter recorded, and restores', function (): void {
    resolve(DeleteModIssue::class)->execute($this->issue, $this->owner);

    expect($this->issue->trashed())->toBeTrue()
        ->and($this->issue->deleted_by)->toBe($this->owner->id);

    resolve(RestoreModIssue::class)->execute($this->issue);

    expect($this->issue->trashed())->toBeFalse()
        ->and($this->issue->deleted_by)->toBeNull();
});

it('bans, re-bans in place and lifts', function (): void {
    $member = User::factory()->create();

    resolve(BanFromModIssues::class)->execute($this->mod, $member, $this->owner, 'Spam', now()->addWeek());
    $ban = resolve(BanFromModIssues::class)->execute($this->mod, $member, $this->owner, null, null);

    expect($this->mod->issueBans()->count())->toBe(1)
        ->and($ban->expires_at)->toBeNull()
        ->and($this->mod->isIssueBanned($member))->toBeTrue();

    resolve(LiftModIssueBan::class)->execute($ban);

    expect($this->mod->isIssueBanned($member))->toBeFalse();
});
