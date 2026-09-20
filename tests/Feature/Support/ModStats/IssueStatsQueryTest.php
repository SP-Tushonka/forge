<?php

declare(strict_types=1);

use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Support\ModStats\IssueStatsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'));
    $this->mod = modWithIssues();
    $this->query = new IssueStatsQuery;
    $this->counts = fn (): array => $this->query->forMod(
        $this->mod,
        CarbonImmutable::parse('2026-09-10', 'UTC'),
        CarbonImmutable::parse('2026-09-20', 'UTC'),
    );
});

it('counts issues opened per day inside the window', function (): void {
    ModIssue::factory()->for($this->mod)->create(['created_at' => '2026-09-12 08:00:00']);
    ModIssue::factory()->for($this->mod)->create(['created_at' => '2026-09-12 23:30:00']);
    ModIssue::factory()->for($this->mod)->create(['created_at' => '2026-09-01 08:00:00']);
    ModIssue::factory()->for(modWithIssues())->create(['created_at' => '2026-09-12 08:00:00']);

    expect(($this->counts)()['opened'])->toBe(['2026-09-12' => 2]);
});

it('counts closed issues by the day they closed and how long they took', function (): void {
    ModIssue::factory()->for($this->mod)->status(ModIssueStatus::Completed)->create([
        'created_at' => '2026-09-12 08:00:00',
        'closed_at' => '2026-09-14 08:00:00',
    ]);
    ModIssue::factory()->for($this->mod)->status(ModIssueStatus::Closed)->create([
        'created_at' => '2026-09-13 08:00:00',
        'closed_at' => '2026-09-14 20:00:00',
    ]);

    $counts = ($this->counts)();

    expect($counts['closed'])->toBe(['2026-09-14' => 2])
        ->and($counts['close_seconds'])->toEqualCanonicalizing([2 * 86400, 36 * 3600]);
});

it('splits the currently open issues by type and status', function (): void {
    ModIssue::factory()->for($this->mod)->create(['type' => ModIssueType::Bug, 'status' => ModIssueStatus::New]);
    ModIssue::factory()->for($this->mod)->create(['type' => ModIssueType::Bug, 'status' => ModIssueStatus::InProgress]);
    ModIssue::factory()->for($this->mod)->create(['type' => ModIssueType::Feature, 'status' => ModIssueStatus::NeedsInfo]);
    ModIssue::factory()->for($this->mod)->status(ModIssueStatus::Completed)->create(['closed_at' => now()]);

    $counts = ($this->counts)();

    // Row order is the database's business, so compare the maps rather than their key order.
    expect($counts['open_now'])->toBe(3)
        ->and($counts['open_by_type'])->toEqual(['bug' => 2, 'feature' => 1])
        ->and($counts['open_by_status'])->toEqual(['new' => 1, 'in_progress' => 1, 'needs_info' => 1]);
});

it('ignores deleted issues', function (): void {
    $issue = ModIssue::factory()->for($this->mod)->create(['created_at' => '2026-09-12 08:00:00']);
    $issue->delete();

    $counts = ($this->counts)();

    expect($counts['opened'])->toBe([])
        ->and($counts['open_now'])->toBe(0);
});

it('returns empty counts for a mod without issues', function (): void {
    $counts = $this->query->forMod(
        Mod::factory()->create(),
        CarbonImmutable::parse('2026-09-10', 'UTC'),
        CarbonImmutable::parse('2026-09-20', 'UTC'),
    );

    expect($counts['opened'])->toBe([])
        ->and($counts['closed'])->toBe([])
        ->and($counts['close_seconds'])->toBe([])
        ->and($counts['open_now'])->toBe(0);
});
