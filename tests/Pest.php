<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModVersion;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Playwright;
use Tests\TestCase;

Playwright::setTimeout(15_000);

// Adopt the CI-recorded Tia baseline when the local dependency graph is missing or stale.
pest()->tia()->always()->locally()->baselined()->filtered();

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

// Tag every test under tests/Browser with the "browser" group so the suite can be filtered locally with
// `--group=browser` or `--exclude-group=browser`, mirroring the dedicated browser job in CI.
pest()->group('browser')->in('Browser');

/**
 * Waits for a browser action's write to reach the database. It waits through the page, which hands control back to the
 * in-process server; a plain sleep would block the very request being waited on.
 */
function waitForWrite(AwaitableWebpage $page, Closure $landed, float $seconds = 10): void
{
    $deadline = microtime(true) + $seconds;

    while (! $landed() && microtime(true) < $deadline) {
        $page->wait(0.1);
    }
}

/**
 * A publicly visible mod with issues switched on. The legacy version (empty SPT constraint) keeps it visible to guests
 * without seeding SPT versions.
 *
 * @param  array<string, mixed>  $attributes
 */
function modWithIssues(array $attributes = []): Mod
{
    $mod = Mod::factory()->create(['issues_enabled' => true, 'published_at' => now()->subDay(), ...$attributes]);

    ModVersion::factory()->recycle($mod)->create([
        'version' => '1.0.0',
        'spt_version_constraint' => '',
        'published_at' => now()->subDay(),
        'disabled' => false,
    ]);

    return $mod;
}
