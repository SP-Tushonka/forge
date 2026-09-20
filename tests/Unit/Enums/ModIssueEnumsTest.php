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

it('labels a declined issue to suit its type', function (ModIssueType $type, string $label): void {
    expect(ModIssueStatus::WontImplement->label($type))->toBe($label);
})->with([
    [ModIssueType::Bug, "Won't fix"],
    [ModIssueType::Compatibility, "Won't fix"],
    [ModIssueType::Feature, "Won't implement"],
    [ModIssueType::Question, "Won't answer"],
]);

it('asks for an affected version only where one makes sense', function (ModIssueType $type, bool $shows, bool $requires): void {
    expect($type->showsAffectedVersion())->toBe($shows)
        ->and($type->requiresAffectedVersion())->toBe($requires);
})->with([
    [ModIssueType::Bug, true, true],
    [ModIssueType::Compatibility, true, true],
    [ModIssueType::Question, true, false],
    [ModIssueType::Feature, false, false],
]);

it('gives every type but a feature request a starting template', function (): void {
    expect(ModIssueType::Bug->template())->toContain('Steps to reproduce')
        ->and(ModIssueType::Compatibility->template())->toContain('Mods involved')
        ->and(ModIssueType::Question->template())->toContain('What are you trying to do?')
        ->and(ModIssueType::Feature->template())->toBe('')
        ->and(ModIssueType::templates())->toHaveCount(3);
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
