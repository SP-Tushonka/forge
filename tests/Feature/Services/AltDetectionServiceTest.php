<?php

declare(strict_types=1);

use App\Models\DisposableEmailBlocklist;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\AltDetectionService;
use App\Support\DataTransferObjects\AltCandidate;
use App\Support\DataTransferObjects\AltInvestigation;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * Insert a raw tracking event row for the given account and IP.
 *
 * @param  array<string, mixed>  $overrides
 */
function altTrackEvent(int $visitorId, string $ip, string $createdAt, array $overrides = []): void
{
    DB::table('tracking_events')->insert(array_merge([
        'event_name' => 'login',
        'event_data' => null,
        'is_moderation_action' => false,
        'ip' => $ip,
        'platform' => 'Windows',
        'browser' => 'Chrome',
        'device' => 'desktop',
        'visitor_type' => User::class,
        'visitor_id' => $visitorId,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $overrides));
}

it('flags a candidate that shares an IP, disposable domain and tight timeline with a high score', function (): void {
    DisposableEmailBlocklist::factory()->create(['domain' => 'zubbamail.de']);

    $suspect = User::factory()->create(['email' => 'syxz@zubbamail.de']);
    $candidate = User::factory()->create(['email' => 'berserker@zubbamail.de']);
    $unrelated = User::factory()->create(['email' => 'someone@gmail.com']);

    $ip = '188.104.195.27';
    altTrackEvent($candidate->id, $ip, '2026-06-01 20:00:00');
    altTrackEvent($candidate->id, $ip, '2026-06-01 20:29:40');
    altTrackEvent($suspect->id, $ip, '2026-06-01 20:30:44');
    altTrackEvent($suspect->id, $ip, '2026-06-17 14:18:19');
    altTrackEvent($unrelated->id, '9.9.9.9', '2026-06-01 20:30:44');

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toHaveCount(1);

    $found = $result->candidates[0];
    expect($found->userId)->toBe($candidate->id)
        ->and($found->matchedSignals)->toContain('shared_ip')
        ->and($found->matchedSignals)->toContain('disposable_email_domain')
        ->and($found->matchedSignals)->toContain('timeline_handoff')
        ->and($found->score)->toBeGreaterThanOrEqual(70)
        ->and($found->sharedIps[0]->ip)->toBe($ip)
        ->and($found->sharedIps[0]->breadth)->toBe(2)
        ->and($found->sharedIps[0]->otherAccounts)->toBeEmpty()
        ->and($found->timeline->gapSeconds)->toBe(64);
});

it('lists the other accounts that also used a shared IP', function (): void {
    $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
    $candidate = User::factory()->create(['name' => 'PrimaryAlt', 'email' => 'primary@beta.test']);
    $otherOne = User::factory()->create(['name' => 'SecondAlt', 'email' => 'second@gamma.test']);
    $otherTwo = User::factory()->create(['name' => 'ThirdAlt', 'email' => 'third@delta.test']);

    $ip = '188.104.195.42';
    altTrackEvent($suspect->id, $ip, '2026-06-01 10:00:00');
    altTrackEvent($candidate->id, $ip, '2026-06-01 10:05:00');
    altTrackEvent($otherOne->id, $ip, '2026-06-01 10:10:00');
    altTrackEvent($otherTwo->id, $ip, '2026-06-01 10:15:00');

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    $found = collect($result->candidates)->firstWhere('userId', $candidate->id);

    expect($found)->not->toBeNull()
        ->and($found->sharedIps[0]->ip)->toBe($ip)
        ->and($found->sharedIps[0]->otherAccounts)->toEqualCanonicalizing(['SecondAlt', 'ThirdAlt'])
        ->and($found->sharedIps[0]->otherAccounts)->not->toContain('PrimaryAlt');
});

it('excludes IPs shared by too many distinct accounts', function (): void {
    $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
    $candidate = User::factory()->create(['email' => 'cand@beta.test']);

    $ip = '10.0.0.1';
    altTrackEvent($suspect->id, $ip, '2026-06-01 10:00:00');
    altTrackEvent($candidate->id, $ip, '2026-06-01 10:05:00');

    // 21 more distinct visitors put this IP over the noisy threshold.
    for ($i = 1; $i <= 21; $i++) {
        altTrackEvent(900000 + $i, $ip, '2026-06-01 10:00:00');
    }

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toBeEmpty()
        ->and($result->excludedNoisyIps)->toBe(1);
});

it('ignores moderation-action events when correlating IPs', function (): void {
    $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
    $candidate = User::factory()->create(['email' => 'cand@beta.test']);

    $ip = '5.5.5.5';
    altTrackEvent($suspect->id, $ip, '2026-06-01 10:00:00');
    altTrackEvent($candidate->id, $ip, '2026-06-01 10:01:00', [
        'event_name' => 'mod_disable',
        'is_moderation_action' => true,
    ]);

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toBeEmpty();
});

it('does not link the banning moderator through the ban event filed against the suspect', function (): void {
    $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
    $moderator = User::factory()->create(['email' => 'mod@beta.test']);

    $moderatorIp = '5.5.5.5';
    $moderatorDevice = ['useragent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/140.0', 'languages' => '["en-US"]'];
    altTrackEvent($suspect->id, '9.9.9.9', '2026-06-01 09:00:00', ['platform' => 'Linux', 'browser' => 'Firefox']);
    altTrackEvent($moderator->id, $moderatorIp, '2026-06-01 09:55:00', $moderatorDevice);
    altTrackEvent($moderator->id, $moderatorIp, '2026-06-01 10:00:00', ['event_name' => 'user_ban', ...$moderatorDevice]);
    // Filed against the suspect, but stamped with the moderator's request IP and device.
    altTrackEvent($suspect->id, $moderatorIp, '2026-06-01 10:00:00', ['event_name' => 'user_banned', ...$moderatorDevice]);

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toBeEmpty()
        ->and($result->suspectIpCount)->toBe(1);
});

it('surfaces same disposable-domain accounts even without a shared IP', function (): void {
    DisposableEmailBlocklist::factory()->create(['domain' => 'throwaway.test']);

    $suspect = User::factory()->create(['email' => 'a@throwaway.test']);
    $candidate = User::factory()->create(['email' => 'b@throwaway.test']);

    altTrackEvent($suspect->id, '1.1.1.1', '2026-06-01 10:00:00');
    altTrackEvent($candidate->id, '2.2.2.2', '2026-06-01 10:00:00');

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toHaveCount(1);

    $found = $result->candidates[0];
    expect($found->userId)->toBe($candidate->id)
        ->and($found->matchedSignals)->toContain('disposable_email_domain')
        ->and($found->matchedSignals)->not->toContain('shared_ip');
});

it('surfaces a since-deleted account that shared an IP, recovered from orphaned events', function (): void {
    $suspect = User::factory()->create(['email' => 'live@alpha.test']);
    $ghostId = 990001; // No users row: an orphaned, since-deleted account.

    $ip = '203.0.113.7';
    altTrackEvent($suspect->id, $ip, '2026-06-10 10:05:00');
    altTrackEvent($ghostId, $ip, '2026-06-10 10:00:00', [
        'event_name' => 'account_delete',
        'event_data' => json_encode(['snapshot' => ['name' => 'GhostUser']]),
    ]);

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toHaveCount(1);

    $found = $result->candidates[0];
    expect($found->userId)->toBe($ghostId)
        ->and($found->deleted)->toBeTrue()
        ->and($found->name)->toBe('GhostUser')
        ->and($found->email)->toBeNull()
        ->and($found->profileUrl)->toBeNull()
        ->and($found->matchedSignals)->toContain('shared_ip')
        ->and($found->sharedIps[0]->ip)->toBe($ip);
});

it('falls back to a placeholder name when a deleted account has no recoverable snapshot', function (): void {
    $suspect = User::factory()->create(['email' => 'live@alpha.test']);
    $ghostId = 990002;

    $ip = '203.0.113.8';
    altTrackEvent($suspect->id, $ip, '2026-06-10 10:05:00');
    altTrackEvent($ghostId, $ip, '2026-06-10 10:00:00', ['event_name' => 'page_view']);

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toHaveCount(1)
        ->and($result->candidates[0]->deleted)->toBeTrue()
        ->and($result->candidates[0]->name)->toBe('Deleted user #'.$ghostId);
});

it('weights a low-breadth shared IP and matching user-agent into a strong score', function (): void {
    $suspect = User::factory()->create(['email' => 'salco@svlco']);
    $candidate = User::factory()->create(['email' => 'syxz@nowhere.test']);

    $ip = '188.104.195.12';
    $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/149.0.0.0';
    $langs = '["de_DE","de"]';

    // Same device; candidate active the day before the suspect registers (burn-and-recreate).
    altTrackEvent($candidate->id, $ip, '2026-06-30 20:14:36', ['useragent' => $agent, 'languages' => $langs]);
    altTrackEvent($suspect->id, $ip, '2026-07-01 15:58:51', ['useragent' => $agent, 'languages' => $langs]);

    // Two unrelated accounts push the IP's breadth to 4 (still under the noisy threshold).
    altTrackEvent(900001, $ip, '2026-05-01 10:00:00');
    altTrackEvent(900002, $ip, '2026-05-02 10:00:00');

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    $found = collect($result->candidates)->firstWhere('userId', $candidate->id);

    expect($found)->not->toBeNull()
        ->and($found->sharedIps[0]->breadth)->toBe(4)
        ->and($found->matchedSignals)->toContain('shared_ip')
        ->and($found->matchedSignals)->toContain('fingerprint')
        ->and($found->matchedSignals)->toContain('timeline_succession')
        ->and($found->score)->toBeGreaterThanOrEqual(50);
});

it('scores a matching locale fingerprint higher than a mismatched one', function (): void {
    $suspect = User::factory()->create(['email' => 'a@alpha.test']);
    $sameLocale = User::factory()->create(['email' => 'b@beta.test']);
    $otherLocale = User::factory()->create(['email' => 'c@gamma.test']);

    $ip = '203.0.113.50';
    altTrackEvent($suspect->id, $ip, '2026-06-01 10:00:00', ['languages' => '["de_DE","de"]']);
    altTrackEvent($sameLocale->id, $ip, '2026-06-01 10:00:00', ['languages' => '["de_DE","de"]']);
    altTrackEvent($otherLocale->id, $ip, '2026-06-01 10:00:00', ['languages' => '["en_US","en"]']);

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    $matched = collect($result->candidates)->firstWhere('userId', $sameLocale->id);
    $mismatched = collect($result->candidates)->firstWhere('userId', $otherLocale->id);

    expect($matched->matchedSignals)->toContain('fingerprint')
        ->and($mismatched->matchedSignals)->not->toContain('fingerprint')
        ->and($matched->score)->toBeGreaterThan($mismatched->score);
});

it('does not surface accounts that only share a common email domain', function (): void {
    $suspect = User::factory()->create(['email' => 'me@gmail.com']);
    altTrackEvent($suspect->id, '1.1.1.1', '2026-06-01 10:00:00');

    // 51 accounts on the same common domain, over the same-domain threshold.
    User::factory()
        ->count(51)
        ->sequence(fn (Sequence $sequence): array => [
            'email' => 'user'.$sequence->index.'@gmail.com',
        ])
        ->create();

    $result = resolve(AltDetectionService::class)->investigate($suspect);

    expect($result->candidates)->toBeEmpty();
});

describe('shared devices', function (): void {
    it('surfaces an account signed in from the suspect\'s browser, even with no shared IP', function (): void {
        $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
        $candidate = User::factory()->create(['email' => 'cand@beta.test']);
        $hash = hash('sha256', 'shared-browser');
        UserDevice::factory()->for($suspect)->create(['device_hash' => $hash]);
        UserDevice::factory()->for($candidate)->create(['device_hash' => $hash]);

        $result = resolve(AltDetectionService::class)->investigate($suspect);

        expect($result->candidates)->toHaveCount(1);

        $found = $result->candidates[0];
        expect($found->userId)->toBe($candidate->id)
            ->and($found->matchedSignals)->toContain('shared_device')
            ->and($found->matchedSignals)->not->toContain('shared_ip')
            ->and($found->score)->toBeGreaterThanOrEqual(50)
            ->and($found->sharedDevices[0]->label)->toBe('Chrome on Windows')
            ->and($found->sharedDevices[0]->otherAccounts)->toBe([]);
    });

    it('ignores a browser shared by more than five accounts', function (): void {
        $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
        $hash = hash('sha256', 'library-pc');
        UserDevice::factory()->for($suspect)->create(['device_hash' => $hash]);
        UserDevice::factory()->count(5)->sequence(fn ($sequence): array => ['user_id' => User::factory()->create(['email' => 'u'.$sequence->index.'@beta.test'])->id])
            ->create(['device_hash' => $hash]);

        $result = resolve(AltDetectionService::class)->investigate($suspect);

        expect($result->candidates)->toBeEmpty()
            ->and($result->excludedSharedDevices)->toBe(1);
    });

    it('finds a deleted banned account through the device hashes kept on its ban', function (): void {
        $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
        $hash = hash('sha256', 'banned-browser');
        UserDevice::factory()->for($suspect)->create(['device_hash' => $hash]);
        DB::table('bans')->insert([
            'bannable_type' => User::class,
            'bannable_id' => 990002,
            'subject_devices' => json_encode([$hash]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = resolve(AltDetectionService::class)->investigate($suspect);

        expect($result->candidates)->toHaveCount(1);

        $found = $result->candidates[0];
        expect($found->userId)->toBe(990002)
            ->and($found->deleted)->toBeTrue()
            ->and($found->matchedSignals)->toContain('shared_device')
            ->and($found->sharedDevices[0]->firstSeen)->toBe('');
    });

    it('reads a handoff on a shared device as a timeline', function (): void {
        $suspect = User::factory()->create(['email' => 'suspect@alpha.test']);
        $candidate = User::factory()->create(['email' => 'cand@beta.test']);
        $hash = hash('sha256', 'handoff-browser');
        UserDevice::factory()->for($suspect)->create(['device_hash' => $hash, 'first_seen_at' => '2026-06-01 08:00:00', 'last_seen_at' => '2026-06-01 10:00:00']);
        UserDevice::factory()->for($candidate)->create(['device_hash' => $hash, 'first_seen_at' => '2026-06-01 10:02:00', 'last_seen_at' => '2026-06-02 10:00:00']);

        $found = resolve(AltDetectionService::class)->investigate($suspect)->candidates[0];

        expect($found->timeline?->type)->toBe('handoff')
            ->and($found->timeline?->ip)->toBe('device Chrome on Windows')
            ->and($found->matchedSignals)->toContain('timeline_handoff');
    });

    it('loads runs stored before device tracking', function (): void {
        $candidate = AltCandidate::fromArray(['user_id' => 1, 'name' => 'Old', 'score' => 40]);
        $investigation = AltInvestigation::fromArray(['suspect' => ['id' => 2, 'name' => 'S', 'email' => 's@x.test'], 'candidates' => []]);

        expect($candidate->sharedDevices)->toBe([])
            ->and($investigation->excludedSharedDevices)->toBe(0);
    });
});
