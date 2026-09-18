@blaze

{{-- Read-only reaction figure for summarised surfaces such as mod cards. Reacting happens on the detail page only, so
     there is deliberately nothing interactive here: that is what lets this sit inside the card's anchor alongside the
     download and endorsement counts, rather than floating outside the card. --}}
@props(['reactableId', 'counts' => [], 'whitelist' => null])

@php
    $emoji = $whitelist ?? collect();

    // Ordered by count, so the representative glyph is whichever emoji people used most. Ties fall back to the
    // whitelist's own display order, which is already the collection's order.
    $used = $emoji
        ->filter(fn($item) => ($counts[$item->id] ?? 0) > 0)
        ->sortByDesc(fn($item) => $counts[$item->id])
        ->values();

    $total = $used->sum(fn($item) => $counts[$item->id]);
    $top = $used->first();

    // Plain-text equivalent of the hover panel. Carried on aria-label rather than title: title would render a second,
    // native browser tooltip on top of the styled one.
    $breakdown = $used->map(fn($item) => Number::format($counts[$item->id]) . ' ' . $item->label)->implode(', ');
@endphp

@if ($top !== null)
    <div
        class="relative flex items-center gap-1"
        x-data="{ open: false }"
        x-on:mouseenter="open = true"
        x-on:mouseleave="open = false"
        {{-- role="img" makes the aria-label authoritative for the whole figure; on a bare div the label would not be
             reliably announced. --}}
        role="img"
        aria-label="{{ Number::format($total) }} {{ __(Str::plural('Reaction', $total)) }}: {{ $breakdown }}"
        data-test="reaction-summary-{{ $reactableId }}"
    >
        <span class="pt-0.5">{{ Number::format($total) }}</span>

        <img
            src="{{ $top->image_url }}"
            alt="{{ $top->alt_text }}"
            class="size-5"
            loading="lazy"
        />

        {{-- Breakdown on hover. aria-hidden because the same information is on the wrapper's aria-label, which is
             what keyboard and screen-reader users get. --}}
        <div
            x-show="open"
            x-cloak
            x-transition.opacity.duration.100ms
            aria-hidden="true"
            class="absolute bottom-full right-0 z-30 mb-1 w-max rounded-lg border border-gray-700 bg-gray-900 px-2 py-1.5 shadow-lg shadow-gray-950"
            data-test="reaction-breakdown-{{ $reactableId }}"
        >
            <div class="flex flex-col gap-1">
                @foreach ($used as $item)
                    <div class="flex items-center gap-1.5 text-xs text-gray-300">
                        <img
                            src="{{ $item->image_url }}"
                            alt="{{ $item->alt_text }}"
                            class="size-4"
                            loading="lazy"
                        />
                        <span class="tabular-nums">{{ Number::format($counts[$item->id]) }}</span>
                        <span class="text-gray-400">{{ $item->label }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif
