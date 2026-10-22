<x-action-section>
    <x-slot name="title">
        {{ __('Ignored Mods') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Mods you have ignored are hidden from you everywhere on the site.') }}
    </x-slot>

    <x-slot name="content">
        @if ($this->ignoredMods->isEmpty())
            <flux:text>{{ __('You haven\'t ignored any mods.') }}</flux:text>
        @else
            <div class="space-y-3">
                @foreach ($this->ignoredMods as $ignore)
                    @continue($ignore->mod === null)
                    <div
                        class="flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-white/5 p-4"
                        data-test="ignored-mod-{{ $ignore->mod_id }}"
                    >
                        <div class="min-w-0">
                            <flux:heading
                                size="sm"
                                class="truncate"
                            >
                                <a
                                    href="{{ $ignore->mod->detail_url }}"
                                    class="hover:underline"
                                >{{ $ignore->mod->name }}</a>
                            </flux:heading>
                            <flux:text size="sm">{{ __('Ignored') }} {{ $ignore->created_at->diffForHumans() }}</flux:text>
                        </div>
                        <flux:button
                            wire:click="stopIgnoring({{ $ignore->mod_id }})"
                            wire:loading.attr="disabled"
                            variant="outline"
                            size="sm"
                            class="shrink-0 whitespace-nowrap"
                        >{{ __('Stop ignoring') }}</flux:button>
                    </div>
                @endforeach
            </div>

            @if ($this->ignoredMods->hasPages())
                <div class="mt-4">
                    {{ $this->ignoredMods->links() }}
                </div>
            @endif
        @endif
    </x-slot>
</x-action-section>
