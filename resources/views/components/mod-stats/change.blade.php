@props(['change'])

@if ($change === null)
    <span
        {{ $attributes->class('text-sm text-gray-500') }}
        title="{{ __('Not enough history to compare yet') }}"
    >—</span>
@else
    @php
        $value = $change['value'];
        $amount = $change['absolute']
            ? Number::format(abs($value))
            : Number::format(abs($value), maxPrecision: 1) . '%';
    @endphp
    <span
        {{ $attributes->class('inline-flex items-center gap-1 text-sm font-medium tabular-nums text-gray-300') }}
        title="{{ __('Compared with the previous period (complete days only)') }}"
    >
        @if ($value > 0)
            <span
                class="text-stats-up"
                aria-hidden="true"
            >▲</span><span class="sr-only">{{ __('Up') }}</span>
        @elseif ($value < 0)
            <span
                class="text-stats-down"
                aria-hidden="true"
            >▼</span><span class="sr-only">{{ __('Down') }}</span>
        @endif
        {{ $amount }}
    </span>
@endif
