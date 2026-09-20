@php
    $report = $this->report;
    $versionColors = [
        'v1' => ['text' => 'text-stats-version-1', 'bg' => 'bg-stats-version-1'],
        'v2' => ['text' => 'text-stats-version-2', 'bg' => 'bg-stats-version-2'],
        'v3' => ['text' => 'text-stats-version-3', 'bg' => 'bg-stats-version-3'],
        'v4' => ['text' => 'text-stats-version-4', 'bg' => 'bg-stats-version-4'],
        'v5' => ['text' => 'text-stats-version-5', 'bg' => 'bg-stats-version-5'],
        'older' => ['text' => 'text-stats-older', 'bg' => 'bg-stats-older'],
    ];
    $engagementSeries = [
        'reactions' => ['label' => __('Reactions'), 'text' => 'text-stats-reactions', 'bg' => 'bg-stats-reactions'],
        'comments' => ['label' => __('Comments'), 'text' => 'text-stats-comments', 'bg' => 'bg-stats-comments'],
        'endorsements' => [
            'label' => __('Endorsements'),
            'text' => 'text-stats-endorsements',
            'bg' => 'bg-stats-endorsements',
        ],
        'list_saves' => ['label' => __('List saves'), 'text' => 'text-stats-list-saves', 'bg' => 'bg-stats-list-saves'],
    ];
@endphp

<x-slot:title>
    {{ __(':mod stats - The Forge', ['mod' => $this->mod->name]) }}
</x-slot>

<x-slot:description>
    {{ __('Download, view and engagement stats for :mod.', ['mod' => $this->mod->name]) }}
</x-slot>

<x-slot:header>
    <h2 class="text-xl font-semibold leading-tight text-gray-100">
        {{ __('Stats') }} ·
        <a
            href="{{ $this->mod->detail_url }}"
            class="hover:underline"
        >{{ $this->mod->name }}</a>
    </h2>
</x-slot>

<div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
    {{-- Not in the header slot: layout slots render outside the component root, where wire:model never binds. --}}
    <x-mod-stats.controls
        :range="$this->range"
        class="justify-end"
    />

    <x-mod-stats.summary :tiles="$report['summary']" />

    <x-mod-stats.traffic-chart
        :points="$report['traffic']"
        :since="$report['since']"
        :range="$this->range"
        :heading="__('Downloads & views')"
    />

    <section class="rounded-lg bg-gray-900 p-4 shadow-xl">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <flux:heading>{{ __('Downloads per version') }}</flux:heading>
            <div class="flex flex-wrap gap-4">
                @foreach ($report['versions']['labels'] as $field => $label)
                    <flux:chart.legend :label="__($label)">
                        <flux:chart.legend.indicator :class="$versionColors[$field]['bg']" />
                    </flux:chart.legend>
                @endforeach
            </div>
        </div>

        @if ($report['versions']['labels'] === [])
            <flux:text>{{ __('No downloads in this period.') }}</flux:text>
        @else
            <flux:chart
                :value="$report['versions']['rows']"
                class="aspect-[2/1] sm:aspect-[3/1]"
            >
                <flux:chart.svg>
                    <flux:chart.stack>
                        @foreach (array_keys($report['versions']['labels']) as $field)
                            <flux:chart.bar
                                :field="$field"
                                :class="$versionColors[$field]['text']"
                                radius="4"
                            />
                        @endforeach
                    </flux:chart.stack>
                    <flux:chart.axis
                        axis="x"
                        field="date"
                        :format="['month' => 'short', 'day' => 'numeric']"
                    >
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                    <flux:chart.axis axis="y">
                        <flux:chart.axis.grid />
                        <flux:chart.axis.tick />
                    </flux:chart.axis>
                </flux:chart.svg>
                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="label" />
                    @foreach ($report['versions']['labels'] as $field => $label)
                        <flux:chart.tooltip.value
                            :field="$field"
                            :label="__($label)"
                        />
                    @endforeach
                </flux:chart.tooltip>
            </flux:chart>

            <x-mod-stats.data-table
                :caption="__('Downloads per version')"
                :rows="$report['versions']['rows']"
                :columns="['label' => __('Period')] + $report['versions']['labels']"
            />
        @endif
    </section>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-lg bg-gray-900 p-4 shadow-xl">
            <flux:heading class="mb-3">{{ __('Top countries (downloads)') }}</flux:heading>

            @if ($report['countries'] === [])
                <flux:text>{{ __('No downloads in this period.') }}</flux:text>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Country') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Downloads') }}</flux:table.column>
                        <flux:table.column class="w-1/3">{{ __('Share') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($report['countries'] as $country)
                            <flux:table.row :key="$country['code']">
                                <flux:table.cell>{{ __($country['label']) }}</flux:table.cell>
                                <flux:table.cell
                                    align="end"
                                    class="tabular-nums"
                                >{{ Number::format($country['downloads']) }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <flux:progress
                                            :value="$country['share']"
                                            class="[--flux-progress-color:var(--color-stats-downloads)]"
                                        />
                                        <span class="w-12 text-end text-xs tabular-nums text-gray-400">
                                            {{ Number::format($country['share'], maxPrecision: 1) }}%
                                        </span>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </section>

        <section class="rounded-lg bg-gray-900 p-4 shadow-xl">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <flux:heading>{{ __('Engagement') }}</flux:heading>
                <div class="flex flex-wrap gap-4">
                    @foreach ($engagementSeries as $series)
                        <flux:chart.legend :label="$series['label']">
                            <flux:chart.legend.indicator :class="$series['bg']" />
                        </flux:chart.legend>
                    @endforeach
                </div>
            </div>

            <flux:chart
                :value="$report['engagement']"
                class="aspect-[2/1]"
            >
                <flux:chart.svg>
                    {{-- Straight segments: smoothing small integer counts overshoots below zero. --}}
                    @foreach ($engagementSeries as $field => $series)
                        <flux:chart.line
                            :field="$field"
                            :class="$series['text']"
                            curve="none"
                        />
                    @endforeach
                    <flux:chart.axis
                        axis="x"
                        field="date"
                        :format="['month' => 'short', 'day' => 'numeric']"
                    >
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
                    @foreach ($engagementSeries as $field => $series)
                        <flux:chart.tooltip.value
                            :field="$field"
                            :label="$series['label']"
                        />
                    @endforeach
                </flux:chart.tooltip>
            </flux:chart>

            <x-mod-stats.data-table
                :caption="__('Engagement')"
                :rows="$report['engagement']"
                :columns="['label' => __('Period')] +
                    array_map(fn(array $series): string => $series['label'], $engagementSeries)"
            />
        </section>
    </div>

    <section class="rounded-lg bg-gray-900 p-4 shadow-xl">
        <flux:heading class="mb-3">
            {{ trans_choice('{0} No mods depend on this one|{1} 1 mod depends on this one|[2,*] :count mods depend on this one', $report['downstream']['count'], ['count' => Number::format($report['downstream']['count'])]) }}
        </flux:heading>

        @if ($report['downstream']['top'] !== [])
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Mod') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Downloads in this period') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($report['downstream']['top'] as $dependent)
                        <flux:table.row :key="$dependent['url']">
                            <flux:table.cell>
                                <flux:link :href="$dependent['url']">{{ $dependent['name'] }}</flux:link>
                            </flux:table.cell>
                            <flux:table.cell
                                align="end"
                                class="tabular-nums"
                            >{{ Number::format($dependent['downloads']) }}</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>

            @if ($report['downstream']['count'] > count($report['downstream']['top']))
                <flux:text
                    size="sm"
                    class="mt-2"
                >
                    {{ __('+ :count more', ['count' => $report['downstream']['count'] - count($report['downstream']['top'])]) }}
                </flux:text>
            @endif
        @endif
    </section>

    @if ($report['issues'] !== null)
        <x-mod-stats.issues :issues="$report['issues']" />
    @endif

    <flux:text
        size="sm"
        class="pb-6 text-center"
    >
        {{ __('All times are UTC.') }}
        @if ($report['since']['downloads'])
            {{ __('Downloads counted since :date.', ['date' => $report['since']['downloads']]) }}
        @endif
        @if ($report['since']['views'])
            {{ __('Page views counted since :date.', ['date' => $report['since']['views']]) }}
        @endif
    </flux:text>
</div>
