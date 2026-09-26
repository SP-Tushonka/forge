<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Ban;
use App\Models\Comment;
use App\Models\OAuthConnection;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function trackedVisit(User $user, string $ip, TrackingEventType $type = TrackingEventType::LOGIN): void
{
    TrackingEvent::factory()->create([
        'event_name' => $type->value,
        'visitor_type' => User::class,
        'visitor_id' => $user->id,
        'ip' => $ip,
    ]);
}

it('copies the account and Discord emails and every IP the account used onto a new ban', function (): void {
    $user = User::factory()->create(['email' => 'banned@example.com']);
    OAuthConnection::factory()->for($user)->create(['provider' => 'discord', 'email' => 'discord@example.com']);
    trackedVisit($user, '203.0.113.1');
    trackedVisit($user, '203.0.113.1');
    Comment::factory()->create(['user_id' => $user->id, 'user_ip' => '203.0.113.2']);

    $ban = $user->ban()->fresh();

    expect($ban->subject_emails)->toBe(['banned@example.com', 'discord@example.com'])
        ->and($ban->subject_ips)->toEqualCanonicalizing(['203.0.113.1', '203.0.113.2']);
});

it('leaves out the moderator IP stamped on ban events', function (): void {
    $user = User::factory()->create();
    trackedVisit($user, '198.51.100.9', TrackingEventType::USER_BANNED);

    expect($user->ban()->fresh()->subject_ips)->toBeNull();
});

it('keeps no placeholder address for an archived Hub account', function (): void {
    $user = User::factory()->create(['email' => 'user1@unclaimed.invalid']);

    expect($user->ban()->fresh()->subject_emails)->toBeNull();
});

it('keeps the ban and refreshes its copy when the banned account is deleted', function (): void {
    $user = User::factory()->create(['email' => 'gone@example.com']);
    $ban = $user->ban();
    Comment::factory()->create(['user_id' => $user->id, 'user_ip' => '203.0.113.5']);

    $user->delete();

    $ban = Ban::query()->findOrFail($ban->id);
    expect($ban->subject_emails)->toBe(['gone@example.com'])
        ->and($ban->subject_ips)->toBe(['203.0.113.5']);
});

it('backfills bans already in force and skips lifted ones', function (): void {
    $active = User::factory()->create(['email' => 'active@example.com']);
    $activeBan = $active->ban();
    $lifted = User::factory()->create(['email' => 'lifted@example.com']);
    $liftedBan = $lifted->ban();
    $lifted->unban();
    DB::table('bans')->update(['subject_emails' => null, 'subject_ips' => null]);

    (require database_path('migrations/2026_09_26_000003_backfill_subject_identifiers_on_active_bans.php'))->up();

    expect(Ban::query()->findOrFail($activeBan->id)->subject_emails)->toBe(['active@example.com'])
        ->and(Ban::withTrashed()->findOrFail($liftedBan->id)->subject_emails)->toBeNull();
});
