<div>
    <flux:tooltip
        content="{{ $this->isIgnored ? __('Show this mod across the site again.') : __('Hide this mod everywhere on the site.') }}"
    >
        <flux:button
            wire:click="toggle"
            wire:loading.attr="disabled"
            variant="outline"
            size="sm"
            class="whitespace-nowrap"
            data-test="ignore-mod-button"
        >
            <div class="flex items-center">
                <flux:icon.eye-slash
                    variant="{{ $this->isIgnored ? 'solid' : 'outline' }}"
                    class="sm:mr-1.5 size-4 {{ $this->isIgnored ? 'text-red-400' : 'text-white' }}"
                />
                <span class="hidden sm:inline">{{ $this->isIgnored ? __('Stop ignoring') : __('Ignore mod') }}</span>
            </div>
        </flux:button>
    </flux:tooltip>
</div>
