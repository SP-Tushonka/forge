<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Mod;
use App\Models\ModUserDownload;
use App\Models\ModVersion;
use App\Models\TrackingEvent;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    // The counter and the tracking event are both written through defer(), so run them inline to assert on them.
    $this->withoutDefer();
});

/**
 * Create a published version of a published mod, which is all the download gate requires of a guest.
 */
function createDownloadableModVersion(): ModVersion
{
    $mod = Mod::factory()->create(['published_at' => now()->subDay()]);

    return ModVersion::factory()->create([
        'mod_id' => $mod->id,
        'published_at' => now()->subDay(),
        'downloads' => 0,
    ]);
}

/**
 * The rate limiter key the controller builds, keyed by user id once authenticated.
 */
function modDownloadRateKey(User $user, ModVersion $version): string
{
    return sprintf('mod.version.download.%s.%d', $user->id, $version->mod_id);
}

function modDownloadWasTracked(ModVersion $version): bool
{
    return TrackingEvent::query()
        ->where('event_name', TrackingEventType::MOD_DOWNLOAD->value)
        ->where('visitable_id', $version->id)
        ->exists();
}

describe('download', function (): void {
    it('records a genuine download', function (): void {
        $user = User::factory()->create();
        $version = createDownloadableModVersion();

        $this->actingAs($user)
            ->get(route('mod.version.download', [$version->mod_id, $version->mod->slug, $version->version]))
            ->assertRedirect($version->link);

        expect($version->refresh()->downloads)->toBe(1)
            ->and(modDownloadWasTracked($version))->toBeTrue()
            ->and(RateLimiter::attempts(modDownloadRateKey($user, $version)))->toBe(1);
    });

    it('does not record a speculative prefetch as a download', function (): void {
        $user = User::factory()->create();
        $version = createDownloadableModVersion();

        $this->actingAs($user)
            ->get(
                route('mod.version.download', [$version->mod_id, $version->mod->slug, $version->version]),
                ['Sec-Purpose' => 'prefetch'],
            )
            ->assertRedirect($version->link);

        expect($version->refresh()->downloads)->toBe(0)
            ->and(modDownloadWasTracked($version))->toBeFalse()
            ->and(RateLimiter::attempts(modDownloadRateKey($user, $version)))->toBe(0);
    });

    it('still enforces the download gate on a prefetch', function (): void {
        $mod = Mod::factory()->create(['published_at' => null]);
        $version = ModVersion::factory()->create([
            'mod_id' => $mod->id,
            'published_at' => now()->subDay(),
        ]);

        $this->get(
            route('mod.version.download', [$mod->id, $mod->slug, $version->version]),
            ['Sec-Purpose' => 'prefetch'],
        )->assertForbidden();
    });
});

describe('last download record', function (): void {
    it('records the version a signed-in user downloaded', function (): void {
        $user = User::factory()->create();
        $version = createDownloadableModVersion();

        $this->actingAs($user)
            ->get(route('mod.version.download', [$version->mod_id, $version->mod->slug, $version->version]));

        $row = ModUserDownload::query()->sole();
        expect($row->user_id)->toBe($user->id)
            ->and($row->mod_version_id)->toBe($version->id)
            ->and($row->version)->toBe($version->version);
    });

    it('replaces the record when the user downloads another version of the mod', function (): void {
        $user = User::factory()->create();
        $first = createDownloadableModVersion();
        $second = ModVersion::factory()->create([
            'mod_id' => $first->mod_id,
            'version' => '99.0.0',
            'published_at' => now()->subDay(),
        ]);

        $this->actingAs($user)->get(route('mod.version.download', [$first->mod_id, $first->mod->slug, $first->version]));
        $this->actingAs($user)->get(route('mod.version.download', [$second->mod_id, $first->mod->slug, '99.0.0']));

        expect(ModUserDownload::query()->sole()->mod_version_id)->toBe($second->id);
    });

    it('records nothing for a guest', function (): void {
        $version = createDownloadableModVersion();

        $this->get(route('mod.version.download', [$version->mod_id, $version->mod->slug, $version->version]));

        expect(ModUserDownload::query()->count())->toBe(0);
    });

    it('records nothing for a prefetch', function (): void {
        $version = createDownloadableModVersion();

        $this->actingAs(User::factory()->create())->get(
            route('mod.version.download', [$version->mod_id, $version->mod->slug, $version->version]),
            ['Sec-Purpose' => 'prefetch'],
        );

        expect(ModUserDownload::query()->count())->toBe(0);
    });

    it('records nothing once the rate limit is hit', function (): void {
        $user = User::factory()->create();
        $version = createDownloadableModVersion();
        foreach (range(1, 5) as $_) {
            RateLimiter::increment(modDownloadRateKey($user, $version));
        }

        $this->actingAs($user)
            ->get(route('mod.version.download', [$version->mod_id, $version->mod->slug, $version->version]))
            ->assertStatus(429);

        expect(ModUserDownload::query()->count())->toBe(0);
    });
});
