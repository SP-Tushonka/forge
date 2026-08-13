<?php

declare(strict_types=1);

use App\Enums\VpsHealthStatus;
use App\Services\Vps\VpsHealthAnalysisService;
use App\Services\Vps\VpsHealthService;
use App\Support\DataTransferObjects\VpsHealthFinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::base')] #[Title('VPS Health - The Forge')] class extends Component
{
    public ?string $refreshError = null;

    public ?string $refreshMessage = null;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');
    }

    /**
     * Ask the host to run a health check out of band.
     *
     * The request is a file drop watched by a systemd path unit, so it returns immediately and the check itself runs a
     * few seconds later. Nothing is reported as refreshed until a snapshot generated after this moment is published.
     */
    public function requestRefresh(): void
    {
        $this->refreshError = null;
        $this->refreshMessage = null;

        $key = 'vps-health:refresh:'.(auth()->id() ?? 0);

        if (RateLimiter::tooManyAttempts($key, 1)) {
            $this->refreshError = __('A check was requested a moment ago. Try again in :seconds seconds.', [
                'seconds' => RateLimiter::availableIn($key),
            ]);

            return;
        }

        RateLimiter::hit($key, config()->integer('vps.refresh_rate_limit_seconds'));

        $baseline = $this->health->snapshot()['generated_at'];

        if (! $this->health->requestRefresh()) {
            $this->refreshError = __('The health check could not be requested. The request directory on the host is missing or not writable.');

            return;
        }

        Cache::put(
            $this->pendingKey(),
            ['requested_at' => now()->getTimestamp(), 'baseline' => $baseline],
            now()->addSeconds(config()->integer('vps.refresh_timeout_seconds') + 60),
        );

        unset($this->refreshPending);
    }

    /**
     * Watch for the requested check to land. Polled only while a refresh is pending.
     *
     * The snapshot is read straight off disk rather than through the shared cache: one viewer waiting on a check must
     * not throw away the copy every other viewer is reading, twice a second, for the length of the wait. The cached
     * copy is dropped once - when the new document is actually there for everyone to see.
     */
    public function pollRefresh(): void
    {
        $pending = $this->pendingRefresh();

        if ($pending === null) {
            return;
        }

        $published = $this->health->publishedAt();

        // A check counts as this user's only if it was generated after they asked for it. The routine timer publishes
        // on its own schedule, and a document it generated before the click is not the answer to that click.
        if ($published !== null && $published !== $pending['baseline'] && $published >= $pending['requested_at']) {
            $this->health->forgetSnapshot();
            $this->resolveRefresh();
            $this->refreshMessage = __('Health check completed.');

            return;
        }

        $timeout = config()->integer('vps.refresh_timeout_seconds');

        if (now()->getTimestamp() - $pending['requested_at'] >= $timeout) {
            $this->resolveRefresh();
            $this->refreshError = __('The requested check did not finish within :seconds seconds. The snapshot figures below are unchanged.', [
                'seconds' => $timeout,
            ]);
        }
    }

    #[Computed]
    public function health(): VpsHealthService
    {
        return app(VpsHealthService::class);
    }

    #[Computed]
    public function analyzer(): VpsHealthAnalysisService
    {
        return new VpsHealthAnalysisService($this->health);
    }

    #[Computed]
    public function verdict(): VpsHealthStatus
    {
        return $this->analyzer->verdict();
    }

    #[Computed]
    public function headline(): string
    {
        return $this->analyzer->headline();
    }

    /**
     * @return list<VpsHealthFinding>
     */
    #[Computed]
    public function immediate(): array
    {
        return $this->analyzer->immediate();
    }

    /**
     * @return list<VpsHealthFinding>
     */
    #[Computed]
    public function recommended(): array
    {
        return $this->analyzer->recommended();
    }

    #[Computed]
    public function refreshPending(): bool
    {
        return $this->pendingRefresh() !== null;
    }

    #[Computed]
    public function memoryStatus(): VpsHealthStatus
    {
        $thresholds = $this->health->thresholds();

        return $this->rate($this->health->memory()['used_pct'], $thresholds['mem_warn_pct'], $thresholds['mem_crit_pct']);
    }

    #[Computed]
    public function swapStatus(): VpsHealthStatus
    {
        $used = $this->health->memory()['swap_used_pct'];

        if ($used === null) {
            return VpsHealthStatus::Unknown;
        }

        return $used > $this->health->thresholds()['swap_warn_pct'] ? VpsHealthStatus::Warning : VpsHealthStatus::Ok;
    }

    #[Computed]
    public function diskStatus(): VpsHealthStatus
    {
        $thresholds = $this->health->thresholds();

        return $this->rate($this->health->disk()['used_pct'], $thresholds['disk_warn_pct'], $thresholds['disk_crit_pct']);
    }

    #[Computed]
    public function loadStatus(): VpsHealthStatus
    {
        $perCpu = $this->health->load()['load_per_cpu'];

        if ($perCpu === null) {
            return VpsHealthStatus::Unknown;
        }

        return $perCpu >= $this->health->thresholds()['load_per_cpu_warn'] ? VpsHealthStatus::Warning : VpsHealthStatus::Ok;
    }

    #[Computed]
    public function certStatus(): VpsHealthStatus
    {
        $days = $this->health->cert()['days_remaining'];

        if (! $this->health->snapshot()['usable'] || $days === null) {
            return VpsHealthStatus::Unknown;
        }

        $thresholds = $this->health->thresholds();

        return match (true) {
            $days <= $thresholds['cert_crit_days'] => VpsHealthStatus::Critical,
            $days <= $thresholds['cert_warn_days'] => VpsHealthStatus::Warning,
            default => VpsHealthStatus::Ok,
        };
    }

    /**
     * Human uptime, or null when it could not be read.
     */
    #[Computed]
    public function uptime(): ?string
    {
        $seconds = $this->health->uptimeSeconds();

        if ($seconds === null) {
            return null;
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $days.'d '.$hours.'h';
        }

        return $hours > 0 ? $hours.'h '.$minutes.'m' : $minutes.'m';
    }

    /**
     * Place a measured percentage against its warning and critical thresholds. An absent reading is Unknown, never Ok.
     */
    private function rate(?float $value, float $warn, float $critical): VpsHealthStatus
    {
        return match (true) {
            $value === null => VpsHealthStatus::Unknown,
            $value >= $critical => VpsHealthStatus::Critical,
            $value >= $warn => VpsHealthStatus::Warning,
            default => VpsHealthStatus::Ok,
        };
    }

    /**
     * The pending refresh, if this user has one.
     *
     * It is held server-side rather than in a public property because the deadline is the only thing stopping the
     * spinner: a browser that could move it could keep itself waiting forever.
     *
     * @return array{requested_at: int, baseline: int|null}|null
     */
    private function pendingRefresh(): ?array
    {
        $pending = Cache::get($this->pendingKey());

        if (! is_array($pending)) {
            return null;
        }

        $requestedAt = $pending['requested_at'] ?? null;
        $baseline = $pending['baseline'] ?? null;

        if (! is_int($requestedAt)) {
            return null;
        }

        return ['requested_at' => $requestedAt, 'baseline' => is_int($baseline) ? $baseline : null];
    }

    private function pendingKey(): string
    {
        return 'vps-health:refresh-pending:'.(auth()->id() ?? 0);
    }

    private function resolveRefresh(): void
    {
        Cache::forget($this->pendingKey());

        unset($this->refreshPending);
    }
};
