<div>
    <flux:pillbox
        wire:model.live="selectedUsers"
        variant="combobox"
        multiple
        :filter="false"
        :label="$label"
        :description="$description ? : null"
        :placeholder="$placeholder"
    >
        <x-slot name="input">
            <flux:pillbox.input
                wire:model.live="search"
                :placeholder="count($selectedUsers) >= $maxUsers ? __('Maximum authors reached') : $placeholder"
                :disabled="count($selectedUsers) >= $maxUsers"
                class="border-0 bg-transparent p-0 text-base shadow-none focus:ring-0 sm:text-sm"
            />
        </x-slot>

        @foreach ($this->searchResults as $user)
            <flux:pillbox.option :value="$user->id">
                <span
                    class="flex min-w-0 items-center gap-2"
                    data-user-id="{{ $user->id }}"
                >
                    <flux:avatar
                        src="{{ $user->profile_photo_url }}"
                        size="xs"
                        circle
                        color="auto"
                        color:seed="{{ $user->id }}"
                    />
                    <span class="truncate">{{ $user->name }}</span>
                    @if ($showUserId)
                        <span
                            class="shrink-0 font-mono text-xs text-zinc-400"
                            data-user-id-label
                        >#{{ $user->id }}</span>
                    @endif
                </span>
            </flux:pillbox.option>
        @endforeach

        <x-slot name="empty">
            <flux:pillbox.option.empty when-loading="{{ __('Searching...') }}">
                {{ __('No users found.') }}
            </flux:pillbox.option.empty>
        </x-slot>
    </flux:pillbox>
</div>
