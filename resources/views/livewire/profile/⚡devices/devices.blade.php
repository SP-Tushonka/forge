<x-action-section>
    <x-slot:title>
        {{ __('Devices') }}
    </x-slot>

    <x-slot:description>
        {{ __('See where your account is signed in, name your devices, and sign out the ones you no longer use.') }}
    </x-slot>

    <x-slot name="content">
        <flux:text class="max-w-xl">
            {{ __('We email you whenever your account is signed in from a browser it has not used before. If you do not recognise a device, sign it out and change your password.') }}
        </flux:text>

        <div class="mt-5 space-y-3">
            @forelse ($this->devices as $device)
                <div
                    wire:key="device-{{ $device->id }}"
                    class="flex flex-wrap items-center gap-4 rounded-xl border border-white/10 bg-white/5 p-4"
                >
                    <div class="shrink-0 text-zinc-400">
                        @if ($device->device_type === 'desktop')
                            <flux:icon.computer-desktop class="size-8" />
                        @else
                            <flux:icon.device-phone-mobile class="size-8" />
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm">{{ $device->label() }}</flux:heading>
                        <flux:text size="xs">
                            {{ collect([$device->location(), $device->last_ip])->filter()->implode(' · ') }}
                        </flux:text>
                        <flux:text size="xs">
                            {{ __('First seen') }} {{ $device->first_seen_at->toFormattedDateString() }} ·
                            @if ($device->device_hash === $this->currentHash)
                                <span class="font-semibold text-green-500">{{ __('This device') }}</span>
                            @else
                                {{ __('Last active') }} {{ $device->last_seen_at->diffForHumans() }}
                            @endif
                        </flux:text>
                    </div>

                    <div class="flex shrink-0 gap-2">
                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="pencil-square"
                            wire:click="startRename({{ $device->id }})"
                        >
                            {{ __('Rename') }}
                        </flux:button>
                        @unless ($device->device_hash === $this->currentHash)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-right-start-on-rectangle"
                                wire:click="signOut({{ $device->id }})"
                                wire:confirm="{{ __('Sign this device out of your account?') }}"
                            >
                                {{ __('Sign out') }}
                            </flux:button>
                        @endunless
                    </div>
                </div>
            @empty
                <flux:text>{{ __('No devices recorded yet.') }}</flux:text>
            @endforelse
        </div>

        <div class="mt-5 flex items-center">
            <flux:button
                variant="primary"
                size="sm"
                class="my-1.5 bg-cyan-700 text-white hover:bg-cyan-600"
                wire:click="confirmSignOutOthers"
                wire:loading.attr="disabled"
            >
                {{ __('Sign Out All Other Devices') }}
            </flux:button>
        </div>

        <flux:modal
            wire:model.live="renaming"
            class="md:w-[500px]"
        >
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Rename device') }}</flux:heading>
                <flux:input
                    wire:model="name"
                    wire:keydown.enter="saveName"
                    maxlength="50"
                    placeholder="{{ __('For example: Work laptop') }}"
                />
                <flux:error name="name" />
                <flux:text size="sm">{{ __('Leave empty to use the browser and system name.') }}</flux:text>
                <div class="flex justify-end gap-3">
                    <flux:button
                        wire:click="$set('renaming', false)"
                        variant="outline"
                        size="sm"
                    >{{ __('Cancel') }}</flux:button>
                    <flux:button
                        wire:click="saveName"
                        variant="primary"
                        size="sm"
                    >{{ __('Save') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        <flux:modal
            wire:model.live="confirmingSignOutOthers"
            class="md:w-[500px] lg:w-[600px]"
        >
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Sign Out All Other Devices') }}</flux:heading>
                <flux:text size="sm">
                    {{ __('Every device except this one will be signed out on its next visit.') }}
                </flux:text>
                @if (auth()->user()?->password !== null)
                    <div>
                        <flux:input
                            type="password"
                            autocomplete="current-password"
                            placeholder="{{ __('Password') }}"
                            wire:model="password"
                            wire:keydown.enter="signOutOthers"
                        />
                        <flux:error name="password" />
                    </div>
                @endif
                <div class="flex justify-end gap-3">
                    <flux:button
                        wire:click="$set('confirmingSignOutOthers', false)"
                        variant="outline"
                        size="sm"
                    >{{ __('Cancel') }}</flux:button>
                    <flux:button
                        wire:click="signOutOthers"
                        variant="primary"
                        size="sm"
                        icon="arrow-right-start-on-rectangle"
                        class="bg-red-600 text-white hover:bg-red-700"
                    >{{ __('Sign Out Other Devices') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    </x-slot>
</x-action-section>
