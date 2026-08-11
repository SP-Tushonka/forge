<?php

declare(strict_types=1);

use App\Jobs\AuditCustomLicensedModsJob;
use App\Models\License;
use App\Models\Mod;
use App\Models\User;
use App\Notifications\CustomLicenseUnpublishedNotification;
use App\Services\License\CustomLicenseVerificationService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    $this->customLicense = License::query()->firstOrCreate(['name' => License::CUSTOM_NAME], ['link' => '']);
    $this->normalLicense = License::factory()->create(['name' => 'MIT']);
});

/**
 * A mod carrying exactly the given source links, replacing the random ones the factory attaches.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<string>  $urls
 */
function auditedMod(array $attributes, array $urls = ['https://github.com/owner/repo']): Mod
{
    $mod = Mod::factory()->create($attributes);

    $mod->sourceCodeLinks()->delete();
    foreach ($urls as $url) {
        $mod->sourceCodeLinks()->create(['url' => $url, 'label' => '']);
    }

    return $mod->refresh();
}

function runLicenseAudit(): void
{
    new AuditCustomLicensedModsJob()->handle(app(CustomLicenseVerificationService::class));
}

describe('failing mods', function (): void {
    it('unpublishes and notifies the owner when the licence file is gone', function (): void {
        Notification::fake();
        Http::fake(['raw.githubusercontent.com/*' => Http::response('', 404)]);

        $owner = User::factory()->create();
        $mod = auditedMod([
            'owner_id' => $owner->id,
            'license_id' => $this->customLicense->id,
            'published_at' => now()->subDay(),
        ]);

        runLicenseAudit();

        expect($mod->fresh()->published_at)->toBeNull();

        Notification::assertSentTo($owner, CustomLicenseUnpublishedNotification::class, fn (CustomLicenseUnpublishedNotification $notification): bool => $notification->mod->is($mod));
    });

    it('unpublishes an unowned mod without a notifiable to send to', function (): void {
        Notification::fake();
        Http::fake(['raw.githubusercontent.com/*' => Http::response('', 404)]);

        $mod = auditedMod([
            'owner_id' => null,
            'license_id' => $this->customLicense->id,
            'published_at' => now()->subDay(),
        ]);

        runLicenseAudit();

        expect($mod->fresh()->published_at)->toBeNull();
        Notification::assertNothingSent();
    });
});

describe('passing mods', function (): void {
    it('leaves a mod published and unnotified when the licence file is still there', function (): void {
        Notification::fake();
        Http::fake(['raw.githubusercontent.com/*' => Http::response('MIT License', 200)]);

        $owner = User::factory()->create();
        $mod = auditedMod([
            'owner_id' => $owner->id,
            'license_id' => $this->customLicense->id,
            'published_at' => now()->subDay(),
        ]);

        runLicenseAudit();

        expect($mod->fresh()->published_at)->not->toBeNull();
        Notification::assertNothingSent();
    });
});

describe('mods outside the audit', function (): void {
    it('never touches a mod on a normal license', function (): void {
        Notification::fake();
        Http::fake();

        $mod = auditedMod([
            'license_id' => $this->normalLicense->id,
            'published_at' => now()->subDay(),
        ]);

        runLicenseAudit();

        Http::assertNothingSent();
        expect($mod->fresh()->published_at)->not->toBeNull();
        Notification::assertNothingSent();
    });

    it('never re-notifies a custom-licensed mod that is already unpublished', function (): void {
        Notification::fake();
        Http::fake();

        auditedMod([
            'license_id' => $this->customLicense->id,
            'published_at' => null,
        ]);

        runLicenseAudit();

        Http::assertNothingSent();
        Notification::assertNothingSent();
    });

    it('skips a mod whose publish date has not arrived yet', function (): void {
        Notification::fake();
        Http::fake();

        auditedMod([
            'license_id' => $this->customLicense->id,
            'published_at' => now()->addWeek(),
        ]);

        runLicenseAudit();

        Http::assertNothingSent();
        Notification::assertNothingSent();
    });
});

describe('rate limiting', function (): void {
    it('audits every mod of one owner without spending their web-form budget', function (): void {
        Notification::fake();
        // A closure per request: the service reads the streamed body, and a shared response would be spent after one.
        Http::fake(['raw.githubusercontent.com/*' => fn (): PromiseInterface => Http::response('MIT License', 200)]);

        $owner = User::factory()->create();
        $count = config()->integer('custom-license.max_attempts') + 2;

        for ($i = 0; $i < $count; $i++) {
            auditedMod([
                'owner_id' => $owner->id,
                'license_id' => $this->customLicense->id,
                'published_at' => now()->subDay(),
            ], ['https://github.com/owner/repo-'.$i]);
        }

        runLicenseAudit();

        Http::assertSentCount($count);
        Notification::assertNothingSent();

        expect(RateLimiter::attempts('custom-license:'.$owner->id))->toBe(0)
            ->and(Mod::query()->withoutGlobalScopes()->whereNull('published_at')->count())->toBe(0);
    });
});
