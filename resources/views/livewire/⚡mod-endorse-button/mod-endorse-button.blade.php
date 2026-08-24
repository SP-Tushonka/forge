<div>
    @guest
        <flux:tooltip content="{{ __('Log in to endorse this mod.') }}">
            <flux:button
                href="{{ route('login') }}"
                variant="outline"
                size="sm"
                class="whitespace-nowrap"
            >
                <div class="flex items-center">
                    <flux:icon.hand-thumb-up
                        variant="outline"
                        class="mr-1.5 size-4 text-white"
                    />
                    {{ __('Endorse') }}
                </div>
            </flux:button>
        </flux:tooltip>
    @else
        @if ($this->denialReason !== null)
            <flux:tooltip content="{{ $this->denialReason }}">
                <div>
                    <flux:button
                        disabled
                        variant="outline"
                        size="sm"
                        class="whitespace-nowrap"
                    >
                        <div class="flex items-center">
                            <flux:icon.hand-thumb-up
                                variant="outline"
                                class="mr-1.5 size-4"
                            />
                            {{ __('Endorse') }}
                        </div>
                    </flux:button>
                </div>
            </flux:tooltip>
        @else
            <flux:button
                wire:click="toggle"
                wire:loading.attr="disabled"
                variant="outline"
                size="sm"
                class="whitespace-nowrap"
                data-test="mod-endorse-button"
            >
                <div class="flex items-center">
                    <flux:icon.hand-thumb-up
                        variant="{{ $this->isEndorsed ? 'solid' : 'outline' }}"
                        class="{{ $this->isEndorsed ? 'text-cyan-400' : 'text-white' }} mr-1.5 size-4"
                    />
                    {{ $this->isEndorsed ? __('Endorsed') : __('Endorse') }}
                </div>
            </flux:button>
        @endif
    @endguest
</div>
