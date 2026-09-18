@blaze

@props([
    'reactableType',
    'reactableId',
    'counts' => [],
    'mine' => [],
    'whitelist' => null,
    'canReact' => false,
])

@php
    $emoji = $whitelist ?? collect();
    $active = $emoji->filter(fn($item) => ($counts[$item->id] ?? 0) > 0);
@endphp

<div
    class="flex flex-wrap items-center gap-1.5"
    data-test="reaction-bar-{{ $reactableType }}-{{ $reactableId }}"
>
    @foreach ($active as $item)
        <button
            type="button"
            @class([
                'flex items-center gap-1 rounded-full border px-2 py-0.5 transition',
                'border-cyan-500 bg-cyan-950/40 text-cyan-300' => in_array(
                    $item->id,
                    $mine,
                    true),
                'border-gray-700 text-gray-300' => !in_array($item->id, $mine, true),
                'hover:border-cyan-600' => $canReact,
                'cursor-default' => !$canReact,
            ])
            aria-label="{{ $item->label }}"
            data-test="reaction-chip-{{ $reactableId }}-{{ $item->shortcode }}"
            @if ($canReact) wire:click="toggleReaction('{{ $reactableType }}', {{ $reactableId }}, {{ $item->id }})"
                wire:loading.attr="disabled"
            @else
                disabled @endif
        >
            <img
                src="{{ $item->image_url }}"
                alt="{{ $item->alt_text }}"
                class="size-4"
                loading="lazy"
            />
            <span class="text-xs tabular-nums">{{ $counts[$item->id] }}</span>
        </button>
    @endforeach

    {{-- A plain Alpine popover rather than flux:dropdown: flux:menu expects flux:menu.item children and imposes its
         own single-column layout, which fights a wrapping emoji grid. Keeping the markup here also guarantees it stays
         inside the Livewire component root, so wire:click binds. --}}
    @if ($canReact && $emoji->isNotEmpty())
        <div
            class="relative"
            x-data="{ open: false }"
            x-on:keydown.escape.window="open = false"
        >
            <button
                type="button"
                x-on:click="open = !open"
                class="flex items-center rounded-full border border-gray-600 bg-gray-800 p-1 text-gray-300 transition hover:border-cyan-500 hover:bg-gray-700 hover:text-cyan-300"
                data-test="reaction-add-{{ $reactableId }}"
                aria-label="{{ __('Add a reaction') }}"
                :aria-expanded="open"
            >
                <flux:icon.face-smile class="size-5" />
            </button>

            <div
                x-show="open"
                x-cloak
                x-transition.opacity.duration.100ms
                x-on:click.outside="open = false"
                data-test="reaction-picker-{{ $reactableId }}"
                {{-- w-max, not a fixed width: the whitelist is staff-editable, so a fixed panel either leaves dead
                     space or orphans the last emoji onto its own row. max-w-xs caps it at roughly ten per row before
                     wrapping, for the day someone whitelists a lot of them. --}}
                class="absolute left-0 top-full z-30 mt-1 flex w-max max-w-xs flex-wrap gap-1 rounded-lg border border-gray-700 bg-gray-900 p-2 shadow-lg shadow-gray-950"
            >
                @foreach ($emoji as $item)
                    <button
                        type="button"
                        wire:click="toggleReaction('{{ $reactableType }}', {{ $reactableId }}, {{ $item->id }})"
                        x-on:click="open = false"
                        @class([
                            'rounded p-1 transition hover:bg-gray-700',
                            'bg-cyan-950/60' => in_array($item->id, $mine, true),
                        ])
                        aria-label="{{ $item->label }}"
                        data-test="reaction-pick-{{ $reactableId }}-{{ $item->shortcode }}"
                    >
                        <img
                            src="{{ $item->image_url }}"
                            alt="{{ $item->alt_text }}"
                            class="size-5"
                            loading="lazy"
                        />
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
