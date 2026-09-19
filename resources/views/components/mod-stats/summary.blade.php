@props(['tiles'])

<div {{ $attributes->class('grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6') }}>
    @foreach ($tiles as $tile)
        <div
            class="rounded-lg bg-gray-900 p-4 shadow-xl"
            wire:key="tile-{{ $tile['key'] }}"
        >
            <div class="text-xs font-medium uppercase tracking-wide text-gray-400">{{ __($tile['label']) }}</div>
            <div class="mt-1 text-2xl font-semibold tabular-nums text-gray-100">{{ Number::format($tile['total']) }}
            </div>
            <x-mod-stats.change
                :change="$tile['change']"
                class="mt-1"
            />
        </div>
    @endforeach
</div>
