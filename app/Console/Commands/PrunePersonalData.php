<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TrackingEventType;
use App\Models\User;
use App\Support\NotificationsToken;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Description('Delete or scrub personal data past the retention window or left behind by deleted accounts, keeping bans and their audit trail')]
#[Signature('data:prune-personal {--dry-run : Count what would be affected without changing anything}')]
final class PrunePersonalData extends Command
{
    private const int CHUNK = 5000;

    private CarbonImmutable $cutoff;

    private bool $dryRun;

    public function handle(): int
    {
        $this->cutoff = CarbonImmutable::now()->subMonths(config()->integer('retention.months'));
        $this->dryRun = (bool) $this->option('dry-run');

        $counts = [
            'tracking_events' => $this->pruneTrackingEvents(),
            'comments (request metadata)' => $this->scrubCommentMetadata(),
            'reports' => $this->pruneReports(),
            'notifications' => $this->pruneNotifications(),
            'account_recoveries' => $this->pruneOlderThanCutoff('account_recoveries'),
            'password_reset_tokens' => $this->pruneOlderThanCutoff('password_reset_tokens', key: 'email'),
            'alt_investigation_runs' => $this->pruneOlderThanCutoff('alt_investigation_runs'),
            'tracking_events (deleted accounts)' => $this->pruneDeletedAccountTrackingEvents(),
            'notifications (deleted accounts)' => $this->deleteInChunks(fn (): Builder => DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->whereNotExists($this->userRow('notifications.notifiable_id'))),
            'alt_investigation_runs (deleted accounts)' => $this->deleteInChunks(fn (): Builder => DB::table('alt_investigation_runs')
                ->whereNotExists($this->userRow('alt_investigation_runs.user_id'))),
            'bans (lifted or expired)' => $this->clearEndedBanIdentifiers(),
        ];

        $this->table(
            ['Rule', $this->dryRun ? 'Would affect' : 'Affected'],
            array_map(fn (string $rule, int $count): array => [$rule, $count], array_keys($counts), $counts),
        );

        return self::SUCCESS;
    }

    private function pruneTrackingEvents(): int
    {
        return $this->deleteInChunks(fn (): Builder => $this->prunableTrackingEvents()->where('created_at', '<', $this->cutoff));
    }

    // Tracking events, notifications and alt runs point at users without a foreign key, so deleting an account leaves them behind.
    private function pruneDeletedAccountTrackingEvents(): int
    {
        return $this->deleteInChunks(fn (): Builder => $this->prunableTrackingEvents()
            ->where('visitor_type', User::class)
            ->whereNotExists($this->userRow('tracking_events.visitor_id')));
    }

    /**
     * Tracking events that may be deleted at all: never the ban audit trail, and never one a report action points at,
     * because report_actions cascades on delete and the moderation history would go with it.
     */
    private function prunableTrackingEvents(): Builder
    {
        $keep = array_map(fn (TrackingEventType $type): string => $type->value, TrackingEventType::banAuditTrail());

        return DB::table('tracking_events')
            // NOT IN is never true for NULL, so unnamed events need their own branch or they would be kept forever.
            ->where(fn (Builder $query): Builder => $query->whereNull('event_name')->orWhereNotIn('event_name', $keep))
            ->whereNotExists(fn (Builder $query): Builder => $query->select(DB::raw(1))
                ->from('report_actions')
                ->whereColumn('report_actions.tracking_event_id', 'tracking_events.id'));
    }

    /**
     * @return Closure(Builder): Builder
     */
    private function userRow(string $userIdColumn): Closure
    {
        return fn (Builder $query): Builder => $query->select(DB::raw(1))->from('users')->whereColumn('users.id', $userIdColumn);
    }

    private function scrubCommentMetadata(): int
    {
        $query = fn (): Builder => DB::table('comments')
            ->where('created_at', '<', $this->cutoff)
            ->where(fn (Builder $query): Builder => $query
                ->where('user_ip', '<>', '')
                ->orWhere('user_agent', '<>', '')
                ->orWhere('referrer', '<>', ''));

        if ($this->dryRun) {
            return $query()->count();
        }

        $total = 0;

        do {
            $ids = $query()->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $updated = $ids->isEmpty() ? 0 : $query()->whereIn('id', $ids)->update(['user_ip' => '', 'user_agent' => '', 'referrer' => '']);
            $total += $updated;
        } while ($updated > 0);

        return $total;
    }

    /**
     * A ban's copy of the account's emails and IPs is kept only while the ban is in force. Banhammer expires bans with a
     * bulk update that fires no model events, so this one sweep clears the copy for lifted and expired bans alike.
     */
    private function clearEndedBanIdentifiers(): int
    {
        $query = DB::table('bans')
            ->where(fn (Builder $query) => $query->whereNotNull('subject_emails')->orWhereNotNull('subject_ips'))
            ->where(fn (Builder $query) => $query->whereNotNull('deleted_at')->orWhere('expired_at', '<=', CarbonImmutable::now()));

        return $this->dryRun ? $query->count() : $query->update(['subject_emails' => null, 'subject_ips' => null]);
    }

    private function pruneReports(): int
    {
        return $this->deleteInChunks(fn (): Builder => DB::table('reports')
            ->where('created_at', '<', $this->cutoff)
            ->whereNotExists(fn (Builder $query): Builder => $query->select(DB::raw(1))
                ->from('report_actions')
                ->whereColumn('report_actions.report_id', 'reports.id')));
    }

    // A query-builder delete skips DatabaseNotificationObserver, so the owners' change tokens are flushed here.
    private function pruneNotifications(): int
    {
        return $this->deleteInChunks(
            fn (): Builder => DB::table('notifications')->where('created_at', '<', $this->cutoff),
            afterDelete: function (Collection $rows): void {
                foreach ($rows->where('notifiable_type', User::class)->pluck('notifiable_id')->unique() as $userId) {
                    if (is_numeric($userId)) {
                        NotificationsToken::flush((int) $userId);
                    }
                }
            },
            columns: ['notifiable_type', 'notifiable_id'],
        );
    }

    private function pruneOlderThanCutoff(string $table, string $key = 'id'): int
    {
        return $this->deleteInChunks(fn (): Builder => DB::table($table)->where('created_at', '<', $this->cutoff), $key);
    }

    /**
     * @param  Closure(): Builder  $query  A fresh query for the rows to delete, rebuilt for every batch
     * @param  (Closure(Collection<int, stdClass>): void)|null  $afterDelete  Receives each deleted batch's selected rows
     * @param  list<string>  $columns  Extra columns to select for $afterDelete
     */
    private function deleteInChunks(Closure $query, string $key = 'id', ?Closure $afterDelete = null, array $columns = []): int
    {
        if ($this->dryRun) {
            return $query()->count();
        }

        $total = 0;

        do {
            $rows = $query()->limit(self::CHUNK)->get([$key, ...$columns]);
            $deleted = $rows->isEmpty() ? 0 : $query()->whereIn($key, $rows->pluck($key))->delete();
            $total += $deleted;

            if ($deleted > 0 && $afterDelete !== null) {
                $afterDelete($rows);
            }
        } while ($deleted > 0);

        return $total;
    }
}
