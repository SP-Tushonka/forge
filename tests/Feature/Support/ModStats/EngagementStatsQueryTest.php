<?php

declare(strict_types=1);

use App\Enums\SpamStatus;
use App\Models\Comment;
use App\Models\Mod;
use App\Models\ModEndorsement;
use App\Models\ModListItem;
use App\Models\Reaction;
use App\Support\ModStats\EngagementStatsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->mod = Mod::factory()->create();
    $this->other = Mod::factory()->create();
    $this->query = new EngagementStatsQuery;
    $this->counts = fn (): array => $this->query->dailyCounts(
        [$this->mod->id],
        CarbonImmutable::parse('2026-09-10', 'UTC'),
        CarbonImmutable::parse('2026-09-18', 'UTC'),
    );
});

it('counts reactions on the mod itself per day inside the window', function (): void {
    $onMod = ['reactable_type' => $this->mod->getMorphClass(), 'reactable_id' => $this->mod->id];
    Reaction::factory()->create([...$onMod, 'created_at' => '2026-09-12 10:00:00']);
    Reaction::factory()->create([...$onMod, 'created_at' => '2026-09-12 23:59:00']);
    Reaction::factory()->create([...$onMod, 'created_at' => '2026-09-01 10:00:00']);
    Reaction::factory()->create(['reactable_type' => $this->other->getMorphClass(), 'reactable_id' => $this->other->id, 'created_at' => '2026-09-12 10:00:00']);

    expect(($this->counts)()['reactions'])->toBe(['2026-09-12' => 2]);
});

it('counts comments and replies but not spam or deleted comments', function (): void {
    $onMod = ['commentable_type' => $this->mod->getMorphClass(), 'commentable_id' => $this->mod->id, 'created_at' => '2026-09-13 10:00:00'];
    $root = Comment::factory()->create($onMod);
    Comment::factory()->reply($root)->create($onMod);
    Comment::factory()->create([...$onMod, 'spam_status' => SpamStatus::SPAM]);
    Comment::factory()->create([...$onMod, 'deleted_at' => '2026-09-14 10:00:00']);

    expect(($this->counts)()['comments'])->toBe(['2026-09-13' => 2]);
});

it('counts active endorsements by the day they were given', function (): void {
    ModEndorsement::factory()->create(['mod_id' => $this->mod->id, 'endorsed_at' => '2026-09-14 08:00:00']);
    ModEndorsement::factory()->revoked()->create(['mod_id' => $this->mod->id, 'endorsed_at' => '2026-09-14 09:00:00']);

    expect(($this->counts)()['endorsements'])->toBe(['2026-09-14' => 1]);
});

it('counts list saves that still exist', function (): void {
    $onMod = ['listable_type' => $this->mod->getMorphClass(), 'listable_id' => $this->mod->id];
    ModListItem::factory()->create([...$onMod, 'created_at' => '2026-09-15 08:00:00']);
    ModListItem::factory()->create([...$onMod, 'created_at' => '2026-09-15 09:00:00', 'tombstoned_at' => '2026-09-16 00:00:00', 'tombstoned_name' => 'Gone']);

    expect(($this->counts)()['list_saves'])->toBe(['2026-09-15' => 1]);
});

it('returns empty counts for no mods', function (): void {
    $counts = $this->query->dailyCounts([], CarbonImmutable::parse('2026-09-10', 'UTC'), CarbonImmutable::parse('2026-09-18', 'UTC'));

    expect($counts)->toBe(['reactions' => [], 'comments' => [], 'endorsements' => [], 'list_saves' => []]);
});
