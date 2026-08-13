@php
    $health = $this->health;
    $snapshot = $health->snapshot();
    $memory = $health->memory();
    $disk = $health->disk();
    $load = $health->load();
    $cpu = $health->cpuUsagePct();
    $thresholds = $health->thresholds();
    $yesterday = $health->yesterday();
    $trends = $health->trends();
    $sizes = $health->sizes();
    $cert = $health->cert();
    $reboot = $health->reboot();
    $services = $health->services();
    $failedUnits = $health->failedUnits();
@endphp

{{-- One poll drives the page: a second one nested inside it would keep firing alongside this one, and every round trip
     re-renders everything. While a check is pending that single poll is the one watching for it. --}}
<div @if ($this->refreshPending) wire:poll.2s="pollRefresh" @else wire:poll.10s @endif>
    <x-slot name="header">
        <div class="flex w-full items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-200">
                    {{ __('VPS Health') }}
                </h2>
            </div>
            @if ($health->host() !== null)
                <flux:badge
                    size="sm"
                    color="zinc"
                >{{ $health->host() }}</flux:badge>
            @endif
        </div>
    </x-slot>

    <div class="px-6 lg:px-8">
        <div class="my-6 space-y-6">

            {{-- 1. Summary and anything that needs acting on now --}}
            <div class="space-y-4 rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                <div class="flex items-start gap-4">
                    <flux:icon
                        :icon="$this->verdict->icon()"
                        class="{{ $this->verdict->textClass() }} mt-1 size-8 shrink-0"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <h3 class="text-lg font-semibold text-gray-100">{{ __('Overall') }}</h3>
                            <flux:badge
                                size="sm"
                                :color="$this->verdict->color()"
                            >{{ __($this->verdict->label()) }}</flux:badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-300">{{ __($this->headline) }}</p>
                    </div>
                </div>

                {{-- Snapshot age, the refresh trigger, and which tier the age actually applies to --}}
                <div class="flex flex-wrap items-end justify-between gap-4 border-t border-gray-800 pt-4">
                    <div class="min-w-0 space-y-1">
                        <p class="text-sm">
                            <span class="text-gray-400">{{ __('Health check last ran') }}</span>
                            @if ($snapshot['generated_at_utc'] !== null)
                                <span
                                    class="{{ $snapshot['stale'] ? 'text-amber-400' : 'text-gray-100' }} font-medium"
                                    title="{{ $snapshot['generated_at_utc']->toDayDateTimeString() }} UTC"
                                >{{ $snapshot['generated_at_utc']->diffForHumans() }}</span>
                            @else
                                <span class="font-medium text-amber-400">{{ __('never') }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ __('That time covers the certificate, trend and size figures only. Memory, swap, disk, load, CPU and service states are measured live every few seconds.') }}
                        </p>
                    </div>
                    <flux:button
                        size="sm"
                        icon="arrow-path"
                        wire:click="requestRefresh"
                        wire:loading.attr="disabled"
                        :disabled="$this->refreshPending"
                    >
                        {{ $this->refreshPending ? __('Checking...') : __('Run health check now') }}
                    </flux:button>
                </div>

                @if ($this->refreshPending)
                    <div class="text-sm text-gray-400">
                        {{ __('Check requested. Waiting for the host to publish a new snapshot...') }}
                    </div>
                @endif

                @if ($this->refreshError !== null)
                    <flux:callout
                        icon="exclamation-triangle"
                        color="red"
                        inline
                    >
                        <flux:callout.text>{{ $this->refreshError }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($this->refreshMessage !== null)
                    <flux:callout
                        icon="check-circle"
                        color="green"
                        inline
                    >
                        <flux:callout.text>{{ $this->refreshMessage }}</flux:callout.text>
                    </flux:callout>
                @endif

                @if ($this->immediate === [])
                    <div class="flex items-center gap-2 rounded-lg border border-green-800 bg-green-950/30 p-4">
                        <flux:icon.check-circle class="size-5 shrink-0 text-green-400" />
                        <p class="text-sm text-gray-200">
                            {{ __('Nothing needs immediate action. Every service is running and no threshold has been breached.') }}
                        </p>
                    </div>
                @else
                    <div class="space-y-3">
                        <h4 class="text-sm font-semibold uppercase tracking-wide text-red-400">
                            {{ __('Act on these now') }}
                        </h4>
                        @foreach ($this->immediate as $finding)
                            <x-vps.finding :finding="$finding" />
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- 2. Advisory items, omitted entirely when there are none --}}
            @if ($this->recommended !== [])
                <div class="space-y-3 rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                    <h3 class="text-lg font-semibold text-gray-100">{{ __('Recommended actions') }}</h3>
                    <p class="text-sm text-gray-400">
                        {{ __('Nothing here is urgent. Schedule it before it becomes urgent.') }}</p>
                    @foreach ($this->recommended as $finding)
                        <x-vps.finding :finding="$finding" />
                    @endforeach
                </div>
            @endif

            {{-- 3. The broader picture --}}
            <div class="space-y-6">
                <div class="flex items-baseline justify-between gap-4">
                    <h3 class="text-lg font-semibold text-gray-100">{{ __('In-depth health') }}</h3>
                    <span class="text-sm text-gray-400">{{ __('Live, measured on every poll') }}</span>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <x-vps.metric
                        :label="__('Memory used')"
                        :value="$memory['used_pct'] !== null ? number_format($memory['used_pct'], 1) . '%' : null"
                        :status="$this->memoryStatus"
                        :detail="$memory['used_kb'] !== null &&
                        $memory['total_kb'] !== null &&
                        $memory['available_kb'] !== null
                            ? Number::fileSize($memory['used_kb'] * 1024, precision: 1) .
                                ' ' .
                                __('of') .
                                ' ' .
                                Number::fileSize($memory['total_kb'] * 1024, precision: 1) .
                                ' - ' .
                                Number::fileSize($memory['available_kb'] * 1024, precision: 1) .
                                ' ' .
                                __('available')
                            : null"
                    />
                    <x-vps.metric
                        :label="__('Swap used')"
                        :value="$memory['swap_used_pct'] !== null
                            ? number_format($memory['swap_used_pct'], 1) . '%'
                            : null"
                        :status="$this->swapStatus"
                        :detail="$memory['swap_used_kb'] !== null && $memory['swap_total_kb'] !== null
                            ? Number::fileSize($memory['swap_used_kb'] * 1024, precision: 1) .
                                ' ' .
                                __('of') .
                                ' ' .
                                Number::fileSize($memory['swap_total_kb'] * 1024, precision: 1)
                            : null"
                    />
                    <x-vps.metric
                        :label="__('Disk used')"
                        :value="$disk['used_pct'] !== null ? number_format($disk['used_pct'], 1) . '%' : null"
                        :status="$this->diskStatus"
                        :detail="$disk['used_bytes'] !== null &&
                        $disk['total_bytes'] !== null &&
                        $disk['free_bytes'] !== null
                            ? Number::fileSize($disk['used_bytes'], precision: 1) .
                                ' ' .
                                __('of') .
                                ' ' .
                                Number::fileSize($disk['total_bytes'], precision: 1) .
                                ' - ' .
                                Number::fileSize($disk['free_bytes'], precision: 1) .
                                ' ' .
                                __('free')
                            : null"
                    />
                    <x-vps.metric
                        :label="__('Load average')"
                        :value="$load['load1'] !== null ? number_format($load['load1'], 2) : null"
                        :status="$this->loadStatus"
                        :detail="$load['load5'] !== null && $load['load15'] !== null
                            ? number_format($load['load5'], 2) .
                                ' / ' .
                                number_format($load['load15'], 2) .
                                ' ' .
                                __('over 5 and 15 minutes') .
                                ($load['cpu_count'] !== null ? ' - ' . $load['cpu_count'] . ' ' . __('cores') : '')
                            : null"
                    />
                    <x-vps.metric
                        :label="__('CPU in use')"
                        :value="$cpu !== null ? number_format($cpu, 1) . '%' : null"
                        :detail="__('Since the previous poll')"
                        :hint="__('Awaiting a second sample')"
                    />
                    <x-vps.metric
                        :label="__('Uptime')"
                        :value="$this->uptime"
                    />
                </div>

                {{-- Service states --}}
                <div class="overflow-hidden rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                    <h4 class="p-6 pb-3 text-lg font-semibold text-gray-100">{{ __('Services') }}</h4>
                    @if ($services === [])
                        <p class="px-6 pb-6 text-sm text-gray-400">{{ __('No units are configured for monitoring.') }}
                        </p>
                    @else
                        <div class="overflow-x-auto px-6 pb-4">
                            <flux:table>
                                <flux:table.columns>
                                    <flux:table.column>{{ __('Unit') }}</flux:table.column>
                                    <flux:table.column align="end">{{ __('State') }}</flux:table.column>
                                </flux:table.columns>
                                <flux:table.rows>
                                    @foreach ($services as $service)
                                        <flux:table.row :key="$service['unit']">
                                            <flux:table.cell class="font-mono text-xs">{{ $service['unit'] }}
                                            </flux:table.cell>
                                            <flux:table.cell align="end">
                                                <flux:badge
                                                    size="sm"
                                                    :color="$service['status']->color()"
                                                >
                                                    {{ $service['state'] ?? __('unknown') }}
                                                </flux:badge>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endforeach
                                </flux:table.rows>
                            </flux:table>
                        </div>
                    @endif
                </div>

                {{-- Everything below comes from the published snapshot, not from a live reading --}}
                @if (!$snapshot['available'])
                    <flux:callout
                        icon="exclamation-triangle"
                        color="amber"
                        inline
                    >
                        <flux:callout.heading>{{ __('No health snapshot') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ $snapshot['error'] }}
                            {{ __('Yesterday\'s aggregates, growth trends, stored sizes and certificate expiry are unavailable until the host publishes one.') }}
                        </flux:callout.text>
                    </flux:callout>
                @else
                    <div class="space-y-4 rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h4 class="text-lg font-semibold text-gray-100">{{ __('From the last health check') }}</h4>
                            @if ($snapshot['stale'])
                                <flux:badge
                                    size="sm"
                                    color="amber"
                                >{{ __('Stale') }}</flux:badge>
                            @endif
                        </div>

                        @if ($snapshot['stale'])
                            <flux:callout
                                icon="clock"
                                color="amber"
                                inline
                            >
                                <flux:callout.text>
                                    {{ __('These figures were captured :when and are no longer treated as current. They are shown for reference only.', ['when' => $snapshot['generated_at_utc']?->diffForHumans() ?? __('at an unknown time')]) }}
                                </flux:callout.text>
                            </flux:callout>
                        @endif

                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                            {{-- Yesterday --}}
                            <div class="space-y-2">
                                <h5 class="text-sm font-semibold uppercase tracking-wide text-gray-400">
                                    {{ __('Yesterday') }}{{ $yesterday['covers'] !== null ? ' (' . $yesterday['covers'] . ')' : '' }}
                                </h5>
                                <dl class="divide-y divide-gray-800 text-sm">
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('CPU average') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['cpu_avg_pct'] !== null ? number_format($yesterday['cpu_avg_pct'], 1) . '%' : __('Unknown') }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('CPU peak') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['cpu_peak_pct'] !== null ? number_format($yesterday['cpu_peak_pct'], 1) . '%' : __('Unknown') }}
                                            @if ($yesterday['cpu_peak_at'] !== null)
                                                <span class="text-gray-500">{{ __('at') }}
                                                    {{ $yesterday['cpu_peak_at'] }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('I/O wait average') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['iowait_avg_pct'] !== null ? number_format($yesterday['iowait_avg_pct'], 2) . '%' : __('Unknown') }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('Memory peak') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['mem_peak_pct'] !== null ? number_format($yesterday['mem_peak_pct'], 1) . '%' : __('Unknown') }}
                                            @if ($yesterday['mem_peak_at'] !== null)
                                                <span class="text-gray-500">{{ __('at') }}
                                                    {{ $yesterday['mem_peak_at'] }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('Lowest available memory') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['mem_avail_min_kb'] !== null ? Number::fileSize($yesterday['mem_avail_min_kb'] * 1024, precision: 1) : __('Unknown') }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('Load average / peak') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['load_avg'] !== null ? number_format($yesterday['load_avg'], 2) : __('Unknown') }}
                                            /
                                            {{ $yesterday['load_peak'] !== null ? number_format($yesterday['load_peak'], 2) : __('Unknown') }}
                                        </dd>
                                    </div>
                                    <div class="flex justify-between py-1.5">
                                        <dt class="text-gray-400">{{ __('Swap-out rate') }}</dt>
                                        <dd class="text-gray-200">
                                            {{ $yesterday['swapout_avg'] !== null ? number_format($yesterday['swapout_avg'], 2) . ' ' . __('pages/s') : __('Unknown') }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            {{-- Trends, sizes and certificate --}}
                            <div class="space-y-4">
                                <div class="space-y-2">
                                    <h5 class="text-sm font-semibold uppercase tracking-wide text-gray-400">
                                        {{ __('Growth trends') }}
                                        @if ($trends['window_days'] !== null)
                                            <span
                                                class="font-normal normal-case text-gray-500">({{ $trends['window_days'] }}
                                                {{ __('day window') }})</span>
                                        @endif
                                    </h5>
                                    <dl class="divide-y divide-gray-800 text-sm">
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Disk') }}</dt>
                                            <dd class="text-right text-gray-200">
                                                @if ($trends['disk']['slope_kb_per_day'] === null)
                                                    <span class="text-gray-500">{{ __('Still collecting') }}</span><br>
                                                    <span class="text-xs text-gray-500">
                                                        @if ($trends['disk']['samples'] !== null && $trends['disk']['span_days'] !== null)
                                                            {{ __(':samples samples over :span days; needs :min days', ['samples' => $trends['disk']['samples'], 'span' => number_format($trends['disk']['span_days'], 1), 'min' => $trends['min_span_days']]) }}
                                                        @else
                                                            {{ __('How much history has been collected is unknown; a trend needs :min days of it', ['min' => $trends['min_span_days']]) }}
                                                        @endif
                                                    </span>
                                                @else
                                                    {{ $trends['disk']['slope_kb_per_day'] < 0 ? '-' : '+' }}{{ Number::fileSize(abs($trends['disk']['slope_kb_per_day']) * 1024, precision: 1) }}
                                                    / {{ __('day') }}
                                                    @if ($trends['disk']['days_to_target'] !== null)
                                                        <br><span
                                                            class="text-xs text-gray-500">{{ __('full in about :days days', ['days' => number_format($trends['disk']['days_to_target'], 0)]) }}</span>
                                                    @endif
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Memory') }}</dt>
                                            <dd class="text-right text-gray-200">
                                                @if ($trends['memory']['slope_pct_per_day'] === null)
                                                    <span class="text-gray-500">{{ __('Still collecting') }}</span>
                                                @else
                                                    {{ number_format($trends['memory']['slope_pct_per_day'], 2) }}%
                                                    / {{ __('day') }}
                                                    @if ($trends['memory']['days_to_target'] !== null)
                                                        <br><span
                                                            class="text-xs text-gray-500">{{ __('reaches :target% in about :days days', ['target' => number_format($thresholds['mem_target_pct'], 0), 'days' => number_format($trends['memory']['days_to_target'], 0)]) }}</span>
                                                    @endif
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Database') }}</dt>
                                            <dd class="text-right text-gray-200">
                                                @if ($trends['database']['slope_bytes_per_day'] === null)
                                                    <span class="text-gray-500">{{ __('Still collecting') }}</span>
                                                @else
                                                    {{ Number::fileSize($trends['database']['slope_bytes_per_day'], precision: 1) }}
                                                    / {{ __('day') }}
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>
                                </div>

                                <div class="space-y-2">
                                    <h5 class="text-sm font-semibold uppercase tracking-wide text-gray-400">
                                        {{ __('Sizes and certificate') }}</h5>
                                    <dl class="divide-y divide-gray-800 text-sm">
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Database') }}</dt>
                                            <dd class="text-gray-200">
                                                {{ $sizes['database_bytes'] !== null ? Number::fileSize($sizes['database_bytes'], precision: 1) : __('Unknown') }}
                                            </dd>
                                        </div>
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Docker') }}</dt>
                                            <dd class="text-gray-200">
                                                {{ $sizes['docker_bytes'] !== null ? Number::fileSize($sizes['docker_bytes'], precision: 1) : __('Unknown') }}
                                            </dd>
                                        </div>
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('TLS certificate') }}</dt>
                                            <dd class="{{ $this->certStatus->textClass() }}">
                                                {{ $cert['days_remaining'] !== null ? $cert['days_remaining'] . ' ' . __('days left') : __('Unknown') }}
                                            </dd>
                                        </div>
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Pending reboot') }}</dt>
                                            <dd class="text-gray-200">
                                                @if ($reboot['required'] === null)
                                                    <span class="text-gray-500">{{ __('Unknown') }}</span>
                                                @else
                                                    {{ $reboot['detail'] ?? ($reboot['required'] ? __('required') : __('not required')) }}
                                                @endif
                                            </dd>
                                        </div>
                                        <div class="flex justify-between py-1.5">
                                            <dt class="text-gray-400">{{ __('Failed systemd units') }}</dt>
                                            <dd class="text-gray-200">
                                                @if ($failedUnits === null)
                                                    <span class="text-gray-500">{{ __('Unknown') }}</span>
                                                @else
                                                    {{ $failedUnits === [] ? __('None') : implode(', ', $failedUnits) }}
                                                @endif
                                            </dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

        </div>
    </div>
</div>
