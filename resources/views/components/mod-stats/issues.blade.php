@props(['issues'])

@php
    $tiles = [
        ['label' => __('Open issues'), 'value' => Number::format($issues['open_now']), 'change' => null],
        [
            'label' => __('Opened'),
            'value' => Number::format($issues['opened']['total']),
            'change' => $issues['opened']['change'],
        ],
        [
            'label' => __('Closed'),
            'value' => Number::format($issues['closed']['total']),
            'change' => $issues['closed']['change'],
        ],
        ['label' => __('Median time to close'), 'value' => $issues['median_close'] ?? '—', 'change' => null],
    ];
@endphp

<section {{ $attributes->class('space-y-4') }}>
    <flux:heading size="lg">{{ __('Issue tracker') }}</flux:heading>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        @foreach ($tiles as $tile)
            <div class="rounded-lg bg-gray-900 p-4 shadow-xl">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ $tile['label'] }}</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-100">{{ $tile['value'] }}</div>
                @if ($tile['change'] !== null)
                    <x-mod-stats.change
                        :change="$tile['change']"
                        class="mt-1"
                    />
                @endif
            </div>
        @endforeach
    </div>

    <div class="rounded-lg bg-gray-900 p-4 shadow-xl">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <flux:heading>{{ __('Issues opened & closed') }}</flux:heading>
            <div class="flex gap-4">
                <flux:chart.legend :label="__('Opened')">
                    <flux:chart.legend.indicator class="bg-stats-issues-opened" />
                </flux:chart.legend>
                <flux:chart.legend :label="__('Closed')">
                    <flux:chart.legend.indicator class="bg-stats-issues-closed" />
                </flux:chart.legend>
            </div>
        </div>

        <flux:chart
            :value="$issues['series']"
            class="aspect-[2/1] sm:aspect-[3/1]"
        >
            <flux:chart.svg>
                <flux:chart.line
                    field="opened"
                    class="text-stats-issues-opened"
                />
                <flux:chart.line
                    field="closed"
                    class="text-stats-issues-closed"
                />
                <flux:chart.axis
                    axis="x"
                    field="date"
                    :format="['month' => 'short', 'day' => 'numeric']"
                >
                    <flux:chart.axis.line />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
                <flux:chart.axis axis="y">
                    <flux:chart.axis.grid />
                    <flux:chart.axis.tick />
                </flux:chart.axis>
            </flux:chart.svg>
            <flux:chart.cursor />
            <flux:chart.tooltip>
                <flux:chart.tooltip.heading field="label" />
                <flux:chart.tooltip.value
                    field="opened"
                    :label="__('Opened')"
                />
                <flux:chart.tooltip.value
                    field="closed"
                    :label="__('Closed')"
                />
            </flux:chart.tooltip>
        </flux:chart>

        <x-mod-stats.data-table
            :caption="__('Issues opened & closed')"
            :rows="$issues['series']"
            :columns="['label' => __('Period'), 'opened' => __('Opened'), 'closed' => __('Closed')]"
        />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach ([['heading' => __('Open issues by type'), 'rows' => $issues['by_type']], ['heading' => __('Open issues by status'), 'rows' => $issues['by_status']]] as $breakdown)
            <div class="rounded-lg bg-gray-900 p-4 shadow-xl">
                <flux:heading class="mb-3">{{ $breakdown['heading'] }}</flux:heading>

                @if ($breakdown['rows'] === [])
                    <flux:text>{{ __('No open issues.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Kind') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Open') }}</flux:table.column>
                            <flux:table.column class="w-1/3">{{ __('Share') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($breakdown['rows'] as $row)
                                <flux:table.row :key="$breakdown['heading'].$row['label']">
                                    <flux:table.cell>{{ $row['label'] }}</flux:table.cell>
                                    <flux:table.cell
                                        align="end"
                                        class="tabular-nums"
                                    >{{ Number::format($row['count']) }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div class="flex items-center gap-2">
                                            <flux:progress
                                                :value="$row['share']"
                                                class="[--flux-progress-color:var(--color-stats-issues-opened)]"
                                            />
                                            <span class="w-12 text-end text-xs tabular-nums text-gray-400">
                                                {{ Number::format($row['share'], maxPrecision: 1) }}%
                                            </span>
                                        </div>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        @endforeach
    </div>
</section>
