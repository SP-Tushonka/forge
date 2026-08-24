<div>
    <x-page-content-title
        :title="__('Most Endorsed Mods')"
        :button-text="__('View All')"
        button-link="/mods?featured=&order=endorsed&query=&versions="
    />

    <nav
        class="isolate flex divide-x divide-gray-800 rounded-xl shadow-md shadow-gray-950 drop-shadow-2xl"
        aria-label="{{ __('Most Endorsed Mods') }}"
        role="tablist"
    >
        @foreach (['7d' => __('7 Days'), '30d' => __('30 Days'), 'all' => __('All Time')] as $value => $label)
            <button
                type="button"
                role="tab"
                wire:click="$set('window', '{{ $value }}')"
                wire:loading.attr="disabled"
                aria-selected="{{ $window === $value ? 'true' : 'false' }}"
                @class([
                    'tab group relative flex min-w-0 flex-1 items-center justify-center gap-1 overflow-hidden px-4 py-4 text-center text-sm transition-colors first:rounded-l-xl last:rounded-r-xl focus:z-10 disabled:opacity-75',
                    'bg-cyan-700 font-extrabold text-white' => $window === $value,
                    'bg-slate-800 font-light text-gray-300 hover:bg-slate-700 hover:text-white' =>
                        $window !== $value,
                ])
            >
                {{ $label }}
            </button>
        @endforeach
    </nav>

    @if ($mods->isEmpty())
        <flux:callout
            icon="hand-thumb-up"
            color="cyan"
            class="my-8"
        >
            <flux:callout.text>
                {{ __('No mods have been endorsed in this period yet. Try a longer window.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="my-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @foreach ($mods as $mod)
                <div wire:key="homepage-endorsed-{{ $mod->id }}">
                    <x-mod.card
                        :mod="$mod"
                        :version="$mod->latestVersion"
                        section="endorsed"
                        :endorsements-count="$endorsementCounts[$mod->id] ?? 0"
                    />
                </div>
            @endforeach
        </div>
    @endif
</div>
