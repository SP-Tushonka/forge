<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $overrides
 */
function writeVpsHealthSnapshot(string $root, array $overrides = []): void
{
    File::put($root.'/status.json', (string) json_encode([
        'generated_at' => now()->getTimestamp(),
        'host' => 'health-host',
        'yesterday' => ['covers' => '2026-08-12', 'cpu_avg_pct' => 36.4, 'cpu_peak_pct' => 58.5],
        'trends' => [
            'window_days' => 30,
            'min_span_days' => 7,
            'disk' => ['slope_kb_per_day' => 120000, 'days_to_target' => 400, 'state' => 'ok', 'samples' => 240, 'span_days' => 21.0],
            'memory' => ['slope_pct_per_day' => 0.01, 'days_to_target' => 800, 'state' => 'ok'],
            'database' => ['slope_bytes_per_day' => 1048576],
        ],
        'sizes' => ['database_bytes' => 569851904, 'docker_bytes' => 928500000],
        'cert' => ['days_remaining' => 84, 'state' => 'ok'],
        'failed_units' => [],
        'reboot_required' => false,
        'reboot_detail' => 'not required',
        'thresholds' => ['disk_warn_pct' => 101, 'disk_crit_pct' => 101, 'mem_target_pct' => 90, 'cert_warn_days' => 21],
        ...$overrides,
    ]));
}

/**
 * The text of one metric tile, bounded by the label of the next, so an assertion about a tile cannot be satisfied by
 * some other tile that happens to say the same thing.
 */
function vpsTile(string $html, string $label): string
{
    $labels = ['Memory used', 'Swap used', 'Disk used', 'Load average', 'CPU in use', 'Uptime', 'Services'];
    $text = (string) preg_replace('/\s+/', ' ', strip_tags($html));
    $start = mb_strpos($text, $label);

    expect($start)->not->toBeFalse("The page has no [{$label}] tile.");

    $start += mb_strlen($label);
    $end = mb_strlen($text);

    foreach ($labels as $other) {
        $next = $other === $label ? false : mb_strpos($text, $other, $start);

        if ($next !== false && $next < $end) {
            $end = $next;
        }
    }

    return mb_trim(mb_substr($text, $start, $end - $start));
}

beforeEach(function (): void {
    $this->root = storage_path('framework/testing/vps-'.Str::random(8));

    File::ensureDirectoryExists($this->root.'/proc');
    File::ensureDirectoryExists($this->root.'/requests');

    File::put($this->root.'/proc/meminfo', implode("\n", [
        'MemTotal:       32000000 kB',
        'MemFree:         8000000 kB',
        'MemAvailable:    8000000 kB',
        'SwapTotal:       2000000 kB',
        'SwapFree:        2000000 kB',
    ]));
    File::put($this->root.'/proc/loadavg', "1.50 1.20 0.90 2/500 12345\n");
    File::put($this->root.'/proc/uptime', "123456.78 987654.32\n");
    File::put($this->root.'/proc/stat', "cpu  100 0 50 850 0 0 0 0 0 0\ncpu0 50 0 25 425 0 0 0 0 0 0\ncpu1 50 0 25 425 0 0 0 0 0 0");

    writeVpsHealthSnapshot($this->root);

    config()->set('vps.proc_path', $this->root.'/proc');
    config()->set('vps.snapshot_path', $this->root.'/status.json');
    config()->set('vps.refresh_request_path', $this->root.'/requests/refresh');
    config()->set('vps.disk_mount', $this->root);
    config()->set('vps.services', ['nginx']);
    config()->set('vps.thresholds.disk_warn_pct', 101.0);
    config()->set('vps.thresholds.disk_crit_pct', 101.0);

    Process::fake(['*is-active*' => Process::result('active')]);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

describe('VpsHealth Authorization', function (): void {
    it('denies access to guests', function (): void {
        $this->get(route('admin.vps-health'))->assertRedirect(route('login'));
    });

    it('denies access to regular users', function (): void {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.vps-health'))
            ->assertForbidden();
    });

    it('denies access to moderators', function (): void {
        $this->actingAs(User::factory()->moderator()->create())
            ->get(route('admin.vps-health'))
            ->assertForbidden();
    });

    it('allows access to administrators', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.vps-health'))
            ->assertOk();
    });
});

describe('VpsHealth Display', function (): void {
    it('states plainly that nothing needs action when the host is healthy', function (): void {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Nothing needs immediate action.')
            ->assertDontSeeText('Recommended actions');
    });

    it('shows when the health check last ran alongside the live tier caveat', function (): void {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Health check last ran')
            ->assertSeeText('are measured live every few seconds')
            ->assertSee(now()->utc()->toDayDateTimeString());
    });

    it('renders live figures and the monitored unit states', function (): void {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('75.0%')
            ->assertSeeText('nginx')
            ->assertSeeText('active');
    });

    it('surfaces a stopped unit as an immediate action', function (): void {
        Process::fake(['*is-active*' => Process::result(output: 'inactive', exitCode: 3)]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Act on these now')
            ->assertSeeText('nginx is not running');
    });

    it('surfaces advisory items in the recommended section', function (): void {
        writeVpsHealthSnapshot($this->root, [
            'reboot_required' => true,
            'reboot_detail' => 'kernel 6.8.0-51 pending',
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Recommended actions')
            ->assertSeeText('A reboot is pending')
            ->assertSeeText('Nothing needs immediate action.');
    });

    it('does not present a stale snapshot as the current state', function (): void {
        writeVpsHealthSnapshot($this->root, ['generated_at' => now()->getTimestamp() - 7200]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('The health snapshot is stale')
            ->assertSeeText('shown for reference only');
    });

    it('reports every live reading it could not take as unknown, tile by tile', function (): void {
        config()->set('vps.proc_path', $this->root.'/gone');
        config()->set('vps.disk_mount', $this->root.'/gone');

        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Some live readings could not be taken')
            ->assertDontSeeText('0.0%')
            ->assertDontSeeText('0.00');

        $html = $component->html();

        foreach (['Memory used', 'Swap used', 'Disk used', 'Load average', 'CPU in use', 'Uptime'] as $label) {
            expect(vpsTile($html, $label))
                ->toContain('Unknown')
                ->not->toContain('0');
        }
    });

    it('renders a measured tile as its measurement', function (): void {
        $html = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->html();

        expect(vpsTile($html, 'Memory used'))
            ->toContain('75.0%')
            ->not->toContain('Unknown');
    });

    it('does not invent a sample count for a trend it could not measure', function (): void {
        config()->set('vps.trends.min_span_days', 9);

        writeVpsHealthSnapshot($this->root, [
            'trends' => ['window_days' => 30, 'disk' => ['slope_kb_per_day' => null, 'samples' => null, 'span_days' => null]],
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Still collecting')
            ->assertSeeText('a trend needs 3 samples over 9 days')
            ->assertDontSeeText('0 samples')
            ->assertDontSeeText('0.0 days');
    });

    it('does not report a trend still gathering history as a failed collection', function (): void {
        writeVpsHealthSnapshot($this->root, [
            'trends' => [
                'window_days' => 30,
                'min_span_days' => 7,
                'min_samples' => 3,
                'disk' => ['slope_kb_per_day' => null, 'days_to_target' => null, 'state' => 'collecting', 'samples' => 19, 'span_days' => 2.3],
                'memory' => ['slope_pct_per_day' => null, 'days_to_target' => null, 'state' => 'collecting', 'samples' => 19, 'span_days' => 2.3],
                'database' => ['slope_bytes_per_day' => null, 'samples' => 19, 'span_days' => 2.3],
            ],
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertDontSeeText('The last health check did not collect everything')
            ->assertSeeText('Still collecting')
            ->assertSeeText('19 samples over 2.3 days');
    });

    it('still reports a trend the host could not measure as a failed collection', function (): void {
        writeVpsHealthSnapshot($this->root, [
            'trends' => [
                'window_days' => 30,
                'disk' => ['slope_kb_per_day' => null, 'state' => 'unknown'],
                'memory' => ['slope_pct_per_day' => null, 'state' => 'unknown'],
            ],
        ]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('The last health check did not collect everything')
            ->assertSeeText('disk growth trend')
            ->assertSeeText('memory growth trend');
    });

    it('reports a failed unit list it never received as unknown rather than none', function (): void {
        writeVpsHealthSnapshot($this->root, ['failed_units' => null]);

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->assertSeeText('Failed systemd units')
            ->assertSeeText('The last health check did not collect everything')
            ->assertDontSeeText('None');
    });

    it('drives the page from a single poll', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health');

        expect(mb_substr_count($component->html(), 'wire:poll'))->toBe(1)
            ->and($component->html())->toContain('wire:poll.10s');

        $component->call('requestRefresh');

        expect(mb_substr_count($component->html(), 'wire:poll'))->toBe(1)
            ->and($component->html())->toContain('wire:poll.2s="pollRefresh"');
    });
});

describe('VpsHealth Refresh', function (): void {
    it('asks the host for a check and waits for the snapshot to change', function (): void {
        $user = User::factory()->admin()->create();

        Livewire::actingAs($user)
            ->test('pages::admin.vps-health')
            ->call('requestRefresh')
            ->assertSet('refreshError', null)
            ->assertSeeText('Checking...');

        expect(File::exists($this->root.'/requests/refresh'))->toBeTrue()
            ->and(Cache::get('vps-health:refresh-pending:'.$user->id))
            ->toMatchArray(['requested_at' => now()->getTimestamp()]);
    });

    it('resolves the pending state once a newer snapshot is published', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->call('requestRefresh');

        $this->travel(5)->seconds();

        writeVpsHealthSnapshot($this->root);

        $component->call('pollRefresh')
            ->assertSet('refreshMessage', 'Health check completed.')
            ->assertDontSeeText('Checking...');
    });

    it('does not let the routine timer resolve the check a user asked for', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->call('requestRefresh');

        $this->travel(3)->seconds();

        // The 15-minute timer publishes on its own schedule: this document was generated before the click, so it is
        // not an answer to it, however different it is from the one that was on disk at the time.
        writeVpsHealthSnapshot($this->root, ['generated_at' => now()->getTimestamp() - 10]);

        $component->call('pollRefresh')
            ->assertSet('refreshMessage', null)
            ->assertSet('refreshError', null)
            ->assertSeeText('Checking...');
    });

    it('gives up on a check that never lands rather than spinning forever', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->call('requestRefresh');

        $this->travel(config()->integer('vps.refresh_timeout_seconds') + 1)->seconds();

        $component->call('pollRefresh')
            ->assertSet('refreshMessage', null)
            ->assertDontSeeText('Checking...');

        expect($component->get('refreshError'))->toContain('did not finish');
    });

    it('keeps the deadline of a pending check out of the browser\'s reach', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->call('requestRefresh');

        expect(fn (): mixed => $component->set('refreshRequestedAt', now()->getTimestamp() + 86400))
            ->toThrow(PublicPropertyNotFoundException::class);

        $this->travel(config()->integer('vps.refresh_timeout_seconds') + 1)->seconds();

        $component->call('pollRefresh')->assertDontSeeText('Checking...');
    });

    it('does not throw away the shared snapshot while one user waits', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->call('requestRefresh');

        $this->travel(3)->seconds();

        writeVpsHealthSnapshot($this->root, [
            'generated_at' => now()->getTimestamp() - 10,
            'cert' => ['days_remaining' => 3, 'state' => 'ok'],
        ]);

        $component->call('pollRefresh');

        // The copy every other viewer reads is still the one they were reading: the wait re-read the file for itself.
        expect(data_get(Cache::get('vps-health:snapshot'), 'data.cert.days_remaining'))->toBe(84);
    });

    it('throttles repeated requests from the same user', function (): void {
        $component = Livewire::actingAs(User::factory()->admin()->create())
            ->test('pages::admin.vps-health')
            ->call('requestRefresh')
            ->call('requestRefresh');

        expect($component->get('refreshError'))->toContain('Try again in');
    });

    it('never reports a refresh it could not request', function (): void {
        config()->set('vps.refresh_request_path', $this->root.'/absent/refresh');

        $user = User::factory()->admin()->create();

        $component = Livewire::actingAs($user)
            ->test('pages::admin.vps-health')
            ->call('requestRefresh')
            ->assertDontSeeText('Checking...');

        expect($component->get('refreshError'))->toContain('could not be requested')
            ->and(Cache::get('vps-health:refresh-pending:'.$user->id))->toBeNull();
    });
});
