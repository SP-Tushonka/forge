@props(['range'])

<div {{ $attributes->class('flex flex-wrap items-center gap-3') }}>
    <flux:radio.group
        wire:model.live="days"
        variant="segmented"
        size="sm"
        :aria-label="__('Range')"
    >
        @foreach (App\Support\DataTransferObjects\StatsRange::ALLOWED_DAYS as $option)
            <flux:radio
                value="{{ $option }}"
                label="{{ $option }}d"
            />
        @endforeach
    </flux:radio.group>

    <flux:radio.group
        wire:model.live="grain"
        variant="segmented"
        size="sm"
        :aria-label="__('Grouping')"
    >
        <flux:radio
            value="daily"
            :label="__('Daily')"
        />
        <flux:radio
            value="weekly"
            :label="__('Weekly')"
            :disabled="$range->days === 7"
        />
    </flux:radio.group>
</div>
