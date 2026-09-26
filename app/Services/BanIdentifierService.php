<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TrackingEventType;
use App\Models\Ban;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Copies a banned account's email and IP addresses onto its ban, so the ban stays enforceable after the 12-month prune
 * or the account's own deletion removes them everywhere else. data:prune-personal clears the copy once the ban ends.
 */
final class BanIdentifierService
{
    public function capture(Ban $ban, User $user): void
    {
        $emails = $this->emails($user);
        $ips = $this->ips($user);

        $ban->forceFill([
            'subject_emails' => $emails === [] ? null : $emails,
            'subject_ips' => $ips === [] ? null : $ips,
        ])->saveQuietly();
    }

    /**
     * The account address and any linked provider address. Archived Hub accounts hold an undeliverable placeholder, and
     * their real address survives only as the account's one-way email tombstone.
     *
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
     * Every address seen in the account's activity records and comment metadata. Ban and unban events are filed
     * against the banned account but carry the moderator's IP, so they are left out.
     *
     * @return list<string>
     */
    private function ips(User $user): array
    {
        $banEvents = array_map(fn (TrackingEventType $type): string => $type->value, TrackingEventType::banAuditTrail());

        $ips = DB::table('tracking_events')
            ->where('visitor_type', $user->getMorphClass())
            ->where('visitor_id', $user->id)
            ->where('ip', '<>', '')
            ->where(fn (Builder $query) => $query->whereNull('event_name')->orWhereNotIn('event_name', $banEvents))
            ->distinct()
            ->pluck('ip')
            ->merge(DB::table('comments')->where('user_id', $user->id)->where('user_ip', '<>', '')->distinct()->pluck('user_ip'))
            ->unique()
            ->all();

        return array_values(array_filter($ips, is_string(...)));
    }
}
