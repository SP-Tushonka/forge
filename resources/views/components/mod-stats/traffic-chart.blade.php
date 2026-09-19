@props(['points', 'heading', 'since', 'range'])

@php
    // Flux plots null as 0, so explain the flat start whenever the range reaches back before collection began.
    $rangeStart = $range->start()->toDateString();
    $collectionStarts = collect([
        'downloads' => __('Downloads are recorded from :date', ['date' => $since['downloads'] ?? __('today')]),
        'views' => __('page views from :date', ['date' => $since['views'] ?? __('today')]),
    ])->filter(fn(string $sentence, string $source): bool => $since[$source] === null || $since[$source] > $rangeStart);
@endphp

<section {{ $attributes->class('rounded-lg bg-gray-900 p-4 shadow-xl') }}>
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <flux:heading>{{ $heading }}</flux:heading>
        <div class="flex gap-4">
            <flux:chart.legend :label="__('Downloads')">
                <flux:chart.legend.indicator class="bg-stats-downloads" />
            </flux:chart.legend>
            <flux:chart.legend :label="__('Views')">
                <flux:chart.legend.indicator class="bg-stats-views" />
            </flux:chart.legend>
        </div>
    </div>

    @if ($collectionStarts->isNotEmpty())
        <flux:text
            size="sm"
            class="mb-3"
        >
            {{ ucfirst($collectionStarts->implode(__(' and '))) }}.
            {{ __('Days before that show as 0 because nothing was collected yet, not because nobody downloaded or visited.') }}
        </flux:text>
    @endif

    <flux:chart
        :value="$points"
        class="aspect-[2/1] sm:aspect-[3/1]"
    >
        <flux:chart.svg>
            <flux:chart.line
                field="downloads"
                class="text-stats-downloads"
            />
            <flux:chart.line
                field="views"
                class="text-stats-views"
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
                field="downloads"
                :label="__('Downloads')"
            />
            <flux:chart.tooltip.value
                field="views"
                :label="__('Views')"
            />
            <flux:chart.tooltip.value
                field="release"
                :label="__('Released')"
            />
        </flux:chart.tooltip>
    </flux:chart>

    <x-mod-stats.data-table
        :caption="$heading"
        :rows="$points"
        :columns="[
            'label' => __('Period'),
            'downloads' => __('Downloads'),
            'views' => __('Views'),
            'release' => __('Released'),
        ]"
    />
</section>
