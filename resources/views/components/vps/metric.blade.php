@props(['label', 'value' => null, 'status' => null, 'detail' => null, 'hint' => null])

<flux:card class="space-y-1">
    <flux:text size="sm">{{ $label }}</flux:text>

    {{-- A reading that could not be taken renders as grey "Unknown", never as a zero and never in a healthy colour. --}}
    @if ($value === null)
        <flux:heading
            size="xl"
            class="text-gray-500"
        >{{ __('Unknown') }}</flux:heading>
        <flux:text
            size="sm"
            class="text-gray-500"
        >{{ $hint ?? __('Not measured') }}</flux:text>
    @else
        <flux:heading
            size="xl"
            class="{{ $status?->textClass() }}"
        >{{ $value }}</flux:heading>
        @if ($detail !== null)
            <flux:text
                size="sm"
                class="text-gray-400"
            >{{ $detail }}</flux:text>
        @endif
    @endif
</flux:card>
