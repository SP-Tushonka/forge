<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Ban;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give bans already in force their copy of the account's emails and IPs. Must land before the first retention run,
     * which scrubs the comment IPs that most of these bans' addresses come from.
     *
     * Deliberately self-contained: it inlines its own copies of BanIdentifierService's email/IP collection instead of
     * calling it, so later changes to that service (such as the device-hash support added by the migrations that
     * follow this one) can't break this migration when it runs on a database that hasn't reached them yet.
     */
    public function up(): void
    {
        Ban::query()
            ->where('bannable_type', (new User)->getMorphClass())
            ->where(fn (Builder $query) => $query->notExpired())
            ->with('bannable')
            ->each(function (Ban $ban): void {
                if ($ban->bannable instanceof User) {
                    $emails = $this->emails($ban->bannable);
                    $ips = $this->ips($ban->bannable);

                    $ban->forceFill([
                        'subject_emails' => $emails === [] ? null : $emails,
                        'subject_ips' => $ips === [] ? null : $ips,
                    ])->saveQuietly();
                }
            });
    }

    /**
     * @return list<string>
     */
    private function emails(User $user): array
    {
        $emails = [];

        foreach ([$user->email, ...$user->oAuthConnections()->pluck('email')->all()] as $email) {
            if (is_string($email) && $email !== '' && ! str_ends_with($email, '@unclaimed.invalid')) {
                $emails[mb_strtolower($email)] ??= $email;
            }
        }

        return array_values($emails);
    }

    /**
     * @return list<string>
     */
    private function ips(User $user): array
    {
        $banEvents = array_map(fn (TrackingEventType $type): string => $type->value, TrackingEventType::banAuditTrail());

        $ips = DB::table('tracking_events')
            ->where('visitor_type', $user->getMorphClass())
            ->where('visitor_id', $user->id)
            ->where('ip', '<>', '')
            ->where(fn (QueryBuilder $query) => $query->whereNull('event_name')->orWhereNotIn('event_name', $banEvents))
            ->distinct()
            ->pluck('ip')
            ->merge(DB::table('comments')->where('user_id', $user->id)->where('user_ip', '<>', '')->distinct()->pluck('user_ip'))
            ->unique()
            ->all();

        return array_values(array_filter($ips, is_string(...)));
    }
};
