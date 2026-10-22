<div>
    @auth
        @if (auth()->id() !== $user->id)
            @if ($this->isIgnoring)
                <flux:button
                    wire:click="stopIgnoring"
                    wire:loading.attr="disabled"
                    variant="outline"
                    size="{{ $size }}"
                    class="whitespace-nowrap"
                    data-test="stop-ignoring-user-content"
                >
                    <div class="flex items-center">
                        <flux:icon.eye class="{{ $size === 'xs' ? 'size-3' : 'size-4' }} mr-1.5 text-white" />
                        {{ __('Stop ignoring content') }}
                    </div>
                </flux:button>
            @else
                <flux:button
                    wire:click="openModal"
                    variant="outline"
                    size="{{ $size }}"
                    class="whitespace-nowrap"
                    data-test="ignore-content-button"
                >
                    <div class="flex items-center">
                        <flux:icon.eye-slash class="{{ $size === 'xs' ? 'size-3' : 'size-4' }} mr-1.5 text-white" />
                        {{ __('Ignore content') }}
                    </div>
                </flux:button>

                <flux:modal
                    wire:model.live="showModal"
                    class="md:w-[500px] lg:w-[600px]"
                >
                    <div class="mb-6 flex items-center gap-3 border-b border-gray-700 pb-6">
                        <flux:icon.eye-slash class="h-8 w-8 text-red-600" />
                        <div>
                            <flux:heading
                                size="xl"
                                class="text-gray-100"
                            >{{ __('Ignore content') }}</flux:heading>
                            <flux:text class="mt-1 text-sm text-gray-400">{{ $user->name }}</flux:text>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <flux:text class="text-sm text-gray-300">
                            {{ __('You are about to ignore all mods, addons and lists belonging to :name, are you sure you want to proceed?', ['name' => $user->name]) }}
                        </flux:text>

                        <flux:radio.group
                            wire:model="scope"
                            data-test="ignore-content-scope"
                        >
                            <flux:radio
                                value="owned"
                                label="{{ __('Owned only') }}"
                                description="{{ __('Hide the mods and addons they own, and their lists.') }}"
                            />
                            <flux:radio
                                value="coauthored"
                                label="{{ __('Owned and co-authored') }}"
                                description="{{ __('Also hide mods and addons they co-author with others.') }}"
                            />
                        </flux:radio.group>
                    </div>

                    <div class="mt-6 flex justify-end gap-3 border-t border-gray-700 pt-6">
                        <flux:button
                            wire:click="$set('showModal', false)"
                            variant="outline"
                        >{{ __('Cancel') }}</flux:button>
                        <flux:button
                            wire:click="confirmIgnore"
                            wire:loading.attr="disabled"
                            variant="danger"
                            data-test="confirm-ignore-content"
                        >{{ __('Ignore') }}</flux:button>
                    </div>
                </flux:modal>
            @endif
        @endif
    @endauth
</div>
