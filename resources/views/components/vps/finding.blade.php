@props(['finding'])

<div
    {{ $attributes->merge(['class' => 'rounded-lg border p-4 ' . ($finding->severity === App\Enums\VpsHealthStatus::Critical ? 'border-red-800 bg-red-950/40' : 'border-amber-800 bg-amber-950/30')]) }}>
    <div class="flex items-start gap-3">
        <flux:icon
            :icon="$finding->severity->icon()"
            class="{{ $finding->severity->textClass() }} mt-0.5 size-5 shrink-0"
        />
        <div class="min-w-0 space-y-1">
            <h4 class="font-semibold text-gray-100">{{ $finding->title }}</h4>
            <p class="text-sm text-gray-300">{{ $finding->detail }}</p>
            <p class="text-sm text-gray-400">
                <span class="font-medium text-gray-300">{{ __('Do this:') }}</span> {{ $finding->action }}
            </p>
        </div>
    </div>
</div>
