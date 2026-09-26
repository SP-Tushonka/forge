<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\AccountRecovery;
use App\Models\AltInvestigationRun;
use App\Models\Ban;
use App\Models\Comment;
use App\Models\CommentVersion;
use App\Models\ModIssueBan;
use App\Models\Report;
use App\Models\ReportAction;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Notifications\NewCommentNotification;
use App\Support\NotificationsToken;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

describe('tracking events', function (): void {
    test('deletes events past the retention window and keeps newer ones', function (): void {
        $old = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::LOGIN->value,
            'created_at' => now()->subMonths(12)->subDay(),
        ]);
        $recent = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::LOGIN->value,
            'created_at' => now()->subMonths(12)->addDay(),
        ]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(TrackingEvent::query()->find($old->id))->toBeNull()
            ->and(TrackingEvent::query()->find($recent->id))->not->toBeNull();
    });

    test('deletes old events that have no event name', function (): void {
        $event = TrackingEvent::factory()->create([
            'event_name' => null,
            'created_at' => now()->subYears(2),
        ]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(TrackingEvent::query()->find($event->id))->toBeNull();
    });

    test('keeps every ban audit event forever', function (): void {
        foreach (TrackingEventType::banAuditTrail() as $type) {
            TrackingEvent::factory()->create([
                'event_name' => $type->value,
                'created_at' => now()->subYears(5),
            ]);
        }

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(TrackingEvent::query()->count())->toBe(count(TrackingEventType::banAuditTrail()));
    });

    test('keeps an old event referenced by a report action, and the action with it', function (): void {
        $action = ReportAction::factory()->create();
        TrackingEvent::query()->whereKey($action->tracking_event_id)->update([
            'event_name' => TrackingEventType::COMMENT_SOFT_DELETE->value,
            'created_at' => now()->subYears(2),
        ]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(TrackingEvent::query()->find($action->tracking_event_id))->not->toBeNull()
            ->and(ReportAction::query()->find($action->id))->not->toBeNull();
    });

    test('respects the configured retention months', function (): void {
        Config::set('retention.months', 6);
        $event = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::LOGIN->value,
            'created_at' => now()->subMonths(7),
        ]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(TrackingEvent::query()->find($event->id))->toBeNull();
    });
});

describe('comments', function (): void {
    test('blanks request metadata on old comments and keeps the comment and its text', function (): void {
        $old = Comment::factory()->create([
            'user_ip' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0',
            'referrer' => 'https://example.com/',
            'created_at' => now()->subMonths(12)->subDay(),
        ]);
        $recent = Comment::factory()->create([
            'user_ip' => '203.0.113.8',
            'created_at' => now()->subMonths(12)->addDay(),
        ]);
        $versions = CommentVersion::query()->where('comment_id', $old->id)->count();
        $updatedAt = $old->fresh()->updated_at;

        $this->artisan('data:prune-personal')->assertSuccessful();

        $old->refresh();
        expect($old->user_ip)->toBe('')
            ->and($old->user_agent)->toBe('')
            ->and($old->referrer)->toBe('')
            ->and($old->updated_at->equalTo($updatedAt))->toBeTrue()
            ->and(CommentVersion::query()->where('comment_id', $old->id)->count())->toBe($versions)
            ->and($recent->fresh()->user_ip)->toBe('203.0.113.8');
    });
});

describe('reports', function (): void {
    test('deletes old reports with no action and keeps actioned ones', function (): void {
        $unactioned = Report::factory()->create(['created_at' => now()->subYears(2)]);
        $action = ReportAction::factory()->create();
        Report::query()->whereKey($action->report_id)->update(['created_at' => now()->subYears(2)]);
        $recent = Report::factory()->create(['created_at' => now()->subMonths(11)]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(Report::query()->find($unactioned->id))->toBeNull()
            ->and(Report::query()->find($action->report_id))->not->toBeNull()
            ->and(ReportAction::query()->find($action->id))->not->toBeNull()
            ->and(Report::query()->find($recent->id))->not->toBeNull();
    });
});

describe('notifications', function (): void {
    test('deletes old notifications and flushes the owner notifications token', function (): void {
        $user = User::factory()->create();
        $old = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => NewCommentNotification::class,
            'data' => [],
            'created_at' => now()->subYears(2),
        ]);
        $recent = $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => NewCommentNotification::class,
            'data' => [],
            'created_at' => now()->subMonths(11),
        ]);
        $token = NotificationsToken::current($user->id);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(DB::table('notifications')->where('id', $old->id)->exists())->toBeFalse()
            ->and(DB::table('notifications')->where('id', $recent->id)->exists())->toBeTrue()
            ->and(NotificationsToken::current($user->id))->not->toBe($token);
    });
});

describe('account recovery and password reset', function (): void {
    test('deletes old account recoveries and keeps recent ones', function (): void {
        $old = AccountRecovery::factory()->create(['created_at' => now()->subYears(2)]);
        $recent = AccountRecovery::factory()->create(['created_at' => now()->subMonths(11)]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(AccountRecovery::query()->find($old->id))->toBeNull()
            ->and(AccountRecovery::query()->find($recent->id))->not->toBeNull();
    });

    test('deletes old password reset tokens and keeps recent ones', function (): void {
        DB::table('password_reset_tokens')->insert([
            ['email' => 'old@example.com', 'token' => 'a', 'created_at' => now()->subYears(2)],
            ['email' => 'new@example.com', 'token' => 'b', 'created_at' => now()->subMonths(11)],
        ]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['new@example.com']);
    });
});

describe('alt investigations', function (): void {
    test('deletes old investigation runs and keeps recent ones', function (): void {
        $old = AltInvestigationRun::factory()->create(['created_at' => now()->subYears(2)]);
        $recent = AltInvestigationRun::factory()->create(['created_at' => now()->subMonths(11)]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(AltInvestigationRun::query()->find($old->id))->toBeNull()
            ->and(AltInvestigationRun::query()->find($recent->id))->not->toBeNull();
    });
});

describe('deleted accounts', function (): void {
    test('deletes recent tracking events of a deleted account but keeps its ban audit trail', function (): void {
        $deleted = User::factory()->create();
        $remaining = User::factory()->create();
        $visit = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::LOGIN->value,
            'visitor_type' => User::class,
            'visitor_id' => $deleted->id,
        ]);
        $banned = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::USER_BANNED->value,
            'visitor_type' => User::class,
            'visitor_id' => $deleted->id,
        ]);
        $other = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::LOGIN->value,
            'visitor_type' => User::class,
            'visitor_id' => $remaining->id,
        ]);
        $deleted->delete();

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(TrackingEvent::query()->find($visit->id))->toBeNull()
            ->and(TrackingEvent::query()->find($banned->id))->not->toBeNull()
            ->and(TrackingEvent::query()->find($other->id))->not->toBeNull();
    });

    test('deletes recent notifications and alt investigations of a deleted account', function (): void {
        $deleted = User::factory()->create();
        $remaining = User::factory()->create();
        foreach ([$deleted, $remaining] as $user) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => NewCommentNotification::class,
                'data' => [],
            ]);
        }
        $deletedRun = AltInvestigationRun::factory()->create(['user_id' => $deleted->id]);
        $remainingRun = AltInvestigationRun::factory()->create(['user_id' => $remaining->id]);
        $deleted->delete();

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(DB::table('notifications')->pluck('notifiable_id')->all())->toBe([$remaining->id])
            ->and(AltInvestigationRun::query()->find($deletedRun->id))->toBeNull()
            ->and(AltInvestigationRun::query()->find($remainingRun->id))->not->toBeNull();
    });
});

describe('bans', function (): void {
    test('never touches bans or issue bans, however old', function (): void {
        $ban = Ban::factory()->create(['created_at' => now()->subYears(10)]);
        $issueBan = ModIssueBan::factory()->create(['created_at' => now()->subYears(10)]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(Ban::query()->find($ban->id))->not->toBeNull()
            ->and(ModIssueBan::query()->find($issueBan->id))->not->toBeNull();
    });

    test('clears the copied emails and IPs once a ban is lifted or expires, and keeps them while it is in force', function (): void {
        $activeBan = User::factory()->create()->ban();
        $lifted = User::factory()->create();
        $liftedBan = $lifted->ban();
        $lifted->unban();
        $expiredBan = User::factory()->create()->ban();
        DB::table('bans')->where('id', $expiredBan->id)->update(['expired_at' => now()->subMinute()]);

        $this->artisan('data:prune-personal')->assertSuccessful();

        expect(Ban::query()->findOrFail($activeBan->id)->subject_emails)->not->toBeNull()
            ->and(Ban::withTrashed()->findOrFail($liftedBan->id)->subject_emails)->toBeNull()
            ->and(Ban::withTrashed()->findOrFail($expiredBan->id)->subject_emails)->toBeNull();
    });
});

describe('moderation history', function (): void {
    test('keeps a report action when its moderator deletes their account', function (): void {
        $action = ReportAction::factory()->create();

        $action->moderator?->delete();

        expect(ReportAction::query()->findOrFail($action->id)->moderator_id)->toBeNull();
    });
});

describe('dry run', function (): void {
    test('reports what it would affect and changes nothing', function (): void {
        $event = TrackingEvent::factory()->create([
            'event_name' => TrackingEventType::LOGIN->value,
            'created_at' => now()->subYears(2),
        ]);

        $this->artisan('data:prune-personal --dry-run')
            ->expectsOutputToContain('Would affect')
            ->assertSuccessful();

        expect(TrackingEvent::query()->find($event->id))->not->toBeNull();
    });
});
