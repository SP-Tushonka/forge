<?php

declare(strict_types=1);

use App\Enums\IssueNotificationLevel;
use App\Enums\ModIssueEventType;
use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;

it('splits statuses into open and closed', function (): void {
    expect(ModIssueStatus::open())->toBe([ModIssueStatus::New, ModIssueStatus::NeedsInfo, ModIssueStatus::InProgress])
        ->and(ModIssueStatus::closed())->toBe([ModIssueStatus::Completed, ModIssueStatus::WontImplement, ModIssueStatus::Duplicate, ModIssueStatus::Closed])
        ->and(ModIssueStatus::NeedsInfo->isOpen())->toBeTrue()
        ->and(ModIssueStatus::Duplicate->isOpen())->toBeFalse();
});

it("labels a declined bug Won't fix and a declined feature Won't implement", function (): void {
    expect(ModIssueStatus::WontImplement->label(ModIssueType::Bug))->toBe("Won't fix")
        ->and(ModIssueStatus::WontImplement->label(ModIssueType::Feature))->toBe("Won't implement");
});

it('maps each notification level to its channels', function (IssueNotificationLevel $level, array $channels): void {
    expect($level->channels())->toBe($channels);
})->with([
    [IssueNotificationLevel::All, ['database', 'mail']],
    [IssueNotificationLevel::Bell, ['database']],
    [IssueNotificationLevel::Off, []],
]);

it('describes a status change with type-aware labels', function (): void {
    expect(ModIssueEventType::StatusChanged->describe('new', 'wont_implement', ModIssueType::Bug))
        ->toBe("changed the status from New to Won't fix");
});

it('describes clearing the fixed version', function (): void {
    expect(ModIssueEventType::FixedVersionChanged->describe('1.2.0', null, ModIssueType::Bug))
        ->toBe('cleared the fixed version');
});
