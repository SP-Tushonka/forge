<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AltIndicatorType;
use App\Enums\TrackingEventType;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\DataTransferObjects\AltIndicator;
use App\Support\DataTransferObjects\AltIndicatorSet;
use App\Support\IpNetwork;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads the indicators alt monitoring works with from an account's own activity: devices, IP addresses and their
 * networks, email domain, user agents, browser prints and countries. Finds the accounts holding an identifier through
 * indexed columns only.
 *
 * @phpstan-type Found array<string, array{type: AltIndicatorType, value: string, label: string, first: ?string, last: ?string}>
 */
final class AltIndicatorService
{
    public const int NOISY_ACCOUNT_THRESHOLD = 20;

    private const int MAX_LISTED_IPS = 250;

    private const int MAX_ACCOUNTS = 500;

    /**
     * Limit a tracking-event query to activity the visitor performed themselves. Ban events are filed against the
     * banned account but carry the moderator's IP and device.
     */
    public static function ownActivity(QueryBuilder $query): void
    {
        $banEvents = array_map(static fn (TrackingEventType $type): string => $type->value, TrackingEventType::banAuditTrail());

        $query->where('is_moderation_action', false)
            ->where(static fn (QueryBuilder $query) => $query->whereNull('event_name')->orWhereNotIn('event_name', $banEvents));
    }

    /**
     * The version-independent print of a browser: platform, browser and languages.
     */
    public static function browserPrint(string $platform, string $browser, string $languages): ?string
    {
        $print = $platform.'|'.$browser.'|'.mb_trim(str_replace(['[', ']', '"'], '', $languages));

        return $print === '||' ? null : $print;
    }

    public static function emailDomain(?string $email): ?string
    {
        if ($email === null || ! str_contains($email, '@')) {
            return null;
        }

        $domain = mb_strtolower(Str::afterLast($email, '@'));

        return $domain === '' ? null : $domain;
    }

    /**
     * The values a watch on this user can tick, grouped by type and newest first, with how many other accounts share
     * each identifier.
     *
     * @return list<AltIndicator>
     */
    public function forUser(User $user): array
    {
        /** @var Found $found */
        $found = [];

        /** @var array<string, array{first: ?string, last: ?string}> $ips */
        $ips = [];

        foreach (UserDevice::query()->where('user_id', $user->id)->get() as $device) {
            $first = $device->first_seen_at->toDateTimeString();
            $last = $device->last_seen_at->toDateTimeString();
            $label = implode(' · ', array_filter([UserDevice::describe($device->browser, $device->platform), $device->location()]));
            $agent = (string) $device->useragent;
            $country = (string) $device->country_code;

            $this->remember($found, AltIndicatorType::Device, $device->device_hash, $label, $first, $last);
            $this->remember($found, AltIndicatorType::UserAgent, $agent, Str::limit($agent, 120), $first, $last);
            $this->remember($found, AltIndicatorType::Country, $country, $country, $first, $last);
            $this->widen($ips, (string) $device->last_ip, $first, $last);
        }

        $ipRows = DB::table('tracking_events')
            ->select('ip as address', DB::raw('MIN(created_at) as first_seen'), DB::raw('MAX(created_at) as last_seen'))
            ->where('visitor_type', User::class)
            ->where('visitor_id', $user->id)
            ->where(self::ownActivity(...))
            ->whereNotNull('ip')
            ->groupBy('ip')
            ->get()
            ->concat(DB::table('comments')
                ->select('user_ip as address', DB::raw('MIN(created_at) as first_seen'), DB::raw('MAX(created_at) as last_seen'))
                ->where('user_id', $user->id)
                ->whereNotNull('user_ip')
                ->groupBy('user_ip')
                ->get());

        foreach ($ipRows as $row) {
            $this->widen($ips, $this->str($row->address), $this->str($row->first_seen), $this->str($row->last_seen));
        }

        uasort($ips, static fn (array $a, array $b): int => strcmp((string) $b['last'], (string) $a['last']));

        foreach (array_slice($ips, 0, self::MAX_LISTED_IPS, true) as $ip => $window) {
            $ip = (string) $ip;
            $this->remember($found, AltIndicatorType::Ip, $ip, $ip, $window['first'], $window['last']);

            $range = IpNetwork::rangeOf($ip);

            if ($range !== null) {
                $this->remember($found, AltIndicatorType::IpRange, $range, $range, $window['first'], $window['last']);
            }
        }

        $qualifierRows = DB::table('tracking_events')
            ->select('useragent', 'platform', 'browser', 'languages', 'country_code', DB::raw('MIN(created_at) as first_seen'), DB::raw('MAX(created_at) as last_seen'))
            ->where('visitor_type', User::class)
            ->where('visitor_id', $user->id)
            ->where(self::ownActivity(...))
            ->groupBy('useragent', 'platform', 'browser', 'languages', 'country_code')
            ->get();

        foreach ($qualifierRows as $row) {
            $first = $this->str($row->first_seen);
            $last = $this->str($row->last_seen);
            $agent = $this->str($row->useragent);
            $country = $this->str($row->country_code);

            $this->remember($found, AltIndicatorType::UserAgent, $agent, Str::limit($agent, 120), $first, $last);
            $this->remember($found, AltIndicatorType::Country, $country, $country, $first, $last);

            $print = self::browserPrint($this->str($row->platform), $this->str($row->browser), $this->str($row->languages));

            if ($print !== null) {
                $this->remember($found, AltIndicatorType::BrowserPrint, $print, $this->printLabel($print), $first, $last);
            }
        }

        $domain = self::emailDomain($user->email);

        if ($domain !== null) {
            $this->remember($found, AltIndicatorType::EmailDomain, $domain, $domain, null, null);
        }

        return $this->withSharedCounts($found, $user->id);
    }

    /**
     * Every indicator value in each account's own activity, for matching. Unlike forUser(), IPs are not capped.
     *
     * @param  list<int>  $userIds
     * @param  list<AltIndicatorType>|null  $types  The types the caller matches on; null loads all of them
     * @return array<int, AltIndicatorSet>
     */
    public function forUsers(array $userIds, ?array $types = null): array
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return [];
        }

        $wants = static fn (AltIndicatorType ...$any): bool => $types === null || array_any($any, static fn (AltIndicatorType $type): bool => in_array($type, $types, true));

        /** @var array<int, array<string, array<string, true>>> $values */
        $values = array_fill_keys($userIds, []);

        if ($wants(AltIndicatorType::Device, AltIndicatorType::UserAgent, AltIndicatorType::Country)) {
            foreach (DB::table('user_devices')->select('user_id', 'device_hash', 'useragent', 'country_code')->whereIn('user_id', $userIds)->get() as $row) {
                $id = $this->int($row->user_id);
                $this->put($values, $id, AltIndicatorType::Device, $this->str($row->device_hash));
                $this->put($values, $id, AltIndicatorType::UserAgent, $this->str($row->useragent));
                $this->put($values, $id, AltIndicatorType::Country, $this->str($row->country_code));
            }
        }

        if ($wants(AltIndicatorType::Ip, AltIndicatorType::IpRange)) {
            $byAccount = static function (QueryBuilder $query, string $ipColumn, string $accountColumn) use ($userIds): void {
                $query->whereIn($accountColumn, $userIds)->whereNotNull($ipColumn);
            };

            foreach ($this->ipSources($byAccount) as $query) {
                foreach ($query->get() as $row) {
                    $id = $this->int($row->account);
                    $ip = $this->str($row->address);
                    $this->put($values, $id, AltIndicatorType::Ip, $ip);
                    $this->put($values, $id, AltIndicatorType::IpRange, IpNetwork::rangeOf($ip));
                }
            }
        }

        if ($wants(AltIndicatorType::UserAgent, AltIndicatorType::Country, AltIndicatorType::BrowserPrint)) {
            $qualifierRows = DB::table('tracking_events')
                ->select('visitor_id', 'useragent', 'platform', 'browser', 'languages', 'country_code')
                ->where('visitor_type', User::class)
                ->whereIn('visitor_id', $userIds)
                ->where(self::ownActivity(...))
                ->groupBy('visitor_id', 'useragent', 'platform', 'browser', 'languages', 'country_code')
                ->get();

            foreach ($qualifierRows as $row) {
                $id = $this->int($row->visitor_id);
                $this->put($values, $id, AltIndicatorType::UserAgent, $this->str($row->useragent));
                $this->put($values, $id, AltIndicatorType::Country, $this->str($row->country_code));
                $this->put($values, $id, AltIndicatorType::BrowserPrint, self::browserPrint($this->str($row->platform), $this->str($row->browser), $this->str($row->languages)));
            }
        }

        if ($wants(AltIndicatorType::EmailDomain)) {
            foreach (DB::table('users')->select('id', 'email')->whereIn('id', $userIds)->get() as $row) {
                $this->put($values, $this->int($row->id), AltIndicatorType::EmailDomain, self::emailDomain($this->str($row->email)));
            }
        }

        return array_map(static fn (array $set): AltIndicatorSet => new AltIndicatorSet($set), $values);
    }

    /**
     * The accounts whose own activity holds an identifier, at most MAX_ACCOUNTS of them. Qualifiers find nobody.
     *
     * @return list<int>
     */
    public function accountsWith(AltIndicatorType $type, string $value): array
    {
        $accounts = match ($type) {
            AltIndicatorType::Device => $this->ints(DB::table('user_devices')->where('device_hash', $value)->distinct()->limit(self::MAX_ACCOUNTS)->pluck('user_id')->all()),
            AltIndicatorType::Ip => array_keys($this->accountsPerIp([$value])[$value] ?? []),
            AltIndicatorType::IpRange => $this->accountsInRange($value),
            AltIndicatorType::EmailDomain => $this->ints(DB::table('users')->whereLike('email', '%@'.$value)->limit(self::MAX_ACCOUNTS)->pluck('id')->all()),
            AltIndicatorType::UserAgent, AltIndicatorType::BrowserPrint, AltIndicatorType::Country => [],
        };

        return array_slice($accounts, 0, self::MAX_ACCOUNTS);
    }

    /**
     * @param  Found  $found
     * @return list<AltIndicator>
     */
    private function withSharedCounts(array $found, int $userId): array
    {
        $ips = [];

        foreach ($found as $item) {
            if ($item['type'] === AltIndicatorType::Ip) {
                $ips[] = $item['value'];
            }
        }

        $perIp = $this->accountsPerIp($ips);
        $indicators = [];

        foreach ($found as $item) {
            $accounts = match (true) {
                $item['type'] === AltIndicatorType::Ip => array_keys($perIp[$item['value']] ?? []),
                $item['type']->isIdentifier() => $this->accountsWith($item['type'], $item['value']),
                default => null,
            };

            $indicators[] = new AltIndicator(
                type: $item['type'],
                value: $item['value'],
                label: $item['label'],
                firstSeen: $item['first'],
                lastSeen: $item['last'],
                sharedWith: $accounts === null ? null : count(array_diff($accounts, [$userId])),
            );
        }

        $order = array_flip(array_map(static fn (AltIndicatorType $type): string => $type->value, AltIndicatorType::cases()));

        usort($indicators, static fn (AltIndicator $a, AltIndicator $b): int => [$order[$a->type->value], $b->lastSeen ?? ''] <=> [$order[$b->type->value], $a->lastSeen ?? '']);

        return $indicators;
    }

    /**
     * Every place an account's own IP address is recorded, as distinct (address, account) rows.
     *
     * @param  Closure(QueryBuilder, string, string): void  $constrain  Narrows one source, given its IP and account columns
     * @return list<QueryBuilder>
     */
    private function ipSources(Closure $constrain): array
    {
        $tracking = DB::table('tracking_events')
            ->select('ip as address', 'visitor_id as account')
            ->where('visitor_type', User::class)
            ->whereNotNull('visitor_id')
            ->where(self::ownActivity(...))
            ->groupBy('ip', 'visitor_id');
        $constrain($tracking, 'ip', 'visitor_id');

        $comments = DB::table('comments')
            ->select('user_ip as address', 'user_id as account')
            ->whereNotNull('user_id')
            ->groupBy('user_ip', 'user_id');
        $constrain($comments, 'user_ip', 'user_id');

        $devices = DB::table('user_devices')
            ->select('last_ip as address', 'user_id as account')
            ->groupBy('last_ip', 'user_id');
        $constrain($devices, 'last_ip', 'user_id');

        return [$tracking, $comments, $devices];
    }

    /**
     * @param  list<string>  $ips
     * @return array<string, array<int, true>>
     */
    private function accountsPerIp(array $ips): array
    {
        if ($ips === []) {
            return [];
        }

        $accounts = [];
        $byAddress = static function (QueryBuilder $query, string $ipColumn) use ($ips): void {
            $query->whereIn($ipColumn, $ips);
        };

        foreach ($this->ipSources($byAddress) as $query) {
            foreach ($query->get() as $row) {
                $accounts[$this->str($row->address)][$this->int($row->account)] = true;
            }
        }

        return $accounts;
    }

    /**
     * @return list<int>
     */
    private function accountsInRange(string $range): array
    {
        $prefix = IpNetwork::likePrefix($range).'%';
        $accounts = [];
        $byPrefix = static function (QueryBuilder $query, string $ipColumn) use ($prefix): void {
            $query->whereLike($ipColumn, $prefix);
        };

        foreach ($this->ipSources($byPrefix) as $query) {
            foreach ($query->get() as $row) {
                if (IpNetwork::rangeOf($this->str($row->address)) === $range) {
                    $accounts[$this->int($row->account)] = true;
                }
            }
        }

        return array_keys($accounts);
    }

    /**
     * @param  Found  $found
     */
    private function remember(array &$found, AltIndicatorType $type, string $value, string $label, ?string $first, ?string $last): void
    {
        if ($value === '') {
            return;
        }

        $key = $type->value.'|'.$value;

        $found[$key] = [
            'type' => $type,
            'value' => $value,
            'label' => $found[$key]['label'] ?? $label,
            'first' => $this->earliest($found[$key]['first'] ?? null, $first),
            'last' => $this->latest($found[$key]['last'] ?? null, $last),
        ];
    }

    /**
     * @param  array<string, array{first: ?string, last: ?string}>  $ips
     */
    private function widen(array &$ips, string $ip, ?string $first, ?string $last): void
    {
        if ($ip === '') {
            return;
        }

        $ips[$ip] = [
            'first' => $this->earliest($ips[$ip]['first'] ?? null, $first),
            'last' => $this->latest($ips[$ip]['last'] ?? null, $last),
        ];
    }

    /**
     * @param  array<int, array<string, array<string, true>>>  $values
     */
    private function put(array &$values, int $id, AltIndicatorType $type, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $values[$id][$type->value][$value] = true;
        }
    }

    private function printLabel(string $print): string
    {
        [$platform, $browser, $languages] = array_pad(explode('|', $print, 3), 3, '');

        return implode(' · ', array_filter([UserDevice::describe($browser, $platform), $languages]));
    }

    private function earliest(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return min($a, $b);
    }

    private function latest(?string $a, ?string $b): ?string
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return max($a, $b);
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<int>
     */
    private function ints(array $values): array
    {
        return array_values(array_map($this->int(...), $values));
    }
}
