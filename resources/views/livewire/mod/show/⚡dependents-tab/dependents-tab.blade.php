@placeholder
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        @for ($i = 0; $i < 4; $i++)
            <div class="rounded-xl bg-gray-950 p-4 shadow-md shadow-gray-950 drop-shadow-2xl sm:p-6">
                <flux:skeleton.group
                    animate="shimmer"
                    class="space-y-4"
                >
                    <flux:skeleton class="h-40 w-full rounded" />
                    <flux:skeleton.line class="w-3/4" />
                    <flux:skeleton.line class="w-1/2" />
                </flux:skeleton.group>
            </div>
        @endfor
    </div>
@endplaceholder

<div id="dependents">
    @if ($this->dependents->count())
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($this->dependents as $dependent)
                <div wire:key="mod-dependent-card-{{ $dependent->id }}">
                    <x-mod.card
                        :mod="$dependent"
                        :version="$dependent->latestVersion"
                        section="dependents"
                        placeholder-bg="bg-gray-900"
                        :reaction-counts="$this->reactionSummary->countsFor($dependent->id)"
                        :reaction-whitelist="$this->reactionWhitelist"
                    />
                </div>
            @endforeach
        </div>
        <div class="mt-5">
            {{ $this->dependents->links() }}
        </div>
    @else
        <div class="rounded-xl bg-gray-950 p-4 shadow-md shadow-gray-950 drop-shadow-2xl sm:p-6">
            <div class="py-8 text-center">
                <flux:icon.cube-transparent class="mx-auto size-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-100">{{ __('No Dependents') }}</h3>
                <p class="mt-1 text-sm text-gray-400">{{ __('No published mod currently depends on this one.') }}</p>
            </div>
        </div>
    @endif
</div>
