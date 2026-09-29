<button
    x-on:click="selectedTab = '{{ $tabValue }}'"
    :aria-selected="selectedTab == '{{ $tabValue }}'"
    :class="selectedTab == '{{ $tabValue }}'
        ? 'bg-cyan-700 text-white shadow-sm shadow-gray-950'
        : 'text-gray-400 hover:bg-gray-800/70 hover:text-gray-100'"
    class="tab group flex min-w-0 flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-sm font-medium transition-colors"
    {{ $attributes }}
>
    {{ __($displayLabel) }}
    @if ($count !== null)
        <span
            :class="selectedTab == '{{ $tabValue }}'
                ? 'bg-cyan-900/70 text-cyan-100'
                : 'bg-gray-800 text-gray-400 group-hover:text-gray-200'"
            class="rounded-full px-1.5 py-px text-xs font-semibold tabular-nums"
        >{{ $count }}</span>
    @endif
</button>
