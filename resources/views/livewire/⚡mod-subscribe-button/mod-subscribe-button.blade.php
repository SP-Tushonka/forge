<div>
    @guest
        <flux:tooltip content="{{ __('Log in to get notified about new versions of this mod.') }}">
            <flux:button
                href="{{ route('login') }}"
                variant="outline"
                size="sm"
                class="whitespace-nowrap"
            >
                <div class="flex items-center">
                    <flux:icon.bell
                        variant="outline"
                        class="sm:mr-1.5 size-4 text-white"
                    />
                    <span class="hidden sm:inline">{{ __('Subscribe') }}</span>
                </div>
            </flux:button>
        </flux:tooltip>
    @else
        <flux:tooltip
            content="{{ $this->isSubscribed ? __('You will be notified when a new version is released.') : __('Get notified when a new version is released.') }}"
        >
            <flux:button
                wire:click="toggle"
                wire:loading.attr="disabled"
                variant="outline"
                size="sm"
                class="whitespace-nowrap"
                data-test="mod-subscribe-button"
            >
                <div class="flex items-center">
                    @if ($this->isSubscribed)
                        <flux:icon.bell-alert
                            variant="solid"
                            class="sm:mr-1.5 size-4 text-cyan-400"
                        />
                        <span class="hidden sm:inline">{{ __('Subscribed') }}</span>
                    @else
                        <flux:icon.bell
                            variant="outline"
                            class="sm:mr-1.5 size-4 text-white"
                        />
                        <span class="hidden sm:inline">{{ __('Subscribe') }}</span>
                    @endif
                </div>
            </flux:button>
        </flux:tooltip>
    @endguest
</div>
