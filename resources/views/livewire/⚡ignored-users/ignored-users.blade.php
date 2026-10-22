<x-action-section>
    <x-slot name="title">
        {{ __('Ignored Users') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Mods, addons and lists from users you ignore are hidden from you. They are not notified and can still interact with you.') }}
    </x-slot>

    <x-slot name="content">
        @if ($this->ignoredUsers->isEmpty())
            <flux:text>{{ __('You haven\'t ignored any users.') }}</flux:text>
        @else
            <div class="space-y-3">
                @foreach ($this->ignoredUsers as $ignore)
                    <div
                        class="flex items-center justify-between gap-4 rounded-xl border border-white/10 bg-white/5 p-4"
                        data-test="ignored-user-{{ $ignore->ignored_user_id }}"
                    >
                        <div class="flex min-w-0 items-center gap-4">
                            <img
                                class="size-12 shrink-0 rounded-full object-cover"
                                src="{{ $ignore->ignoredUser->profile_photo_url }}"
                                alt="{{ $ignore->ignoredUser->name }}"
                            >
                            <div class="min-w-0">
                                <flux:heading
                                    size="sm"
                                    class="truncate"
                                >
                                    <a
                                        href="{{ route('user.show', ['userId' => $ignore->ignoredUser->id, 'slug' => $ignore->ignoredUser->slug]) }}"
                                        class="hover:underline"
                                    >{{ $ignore->ignoredUser->name }}</a>
                                </flux:heading>
                                <flux:text size="sm">
                                    {{ $ignore->include_coauthored ? __('Owned and co-authored') : __('Owned only') }}
                                    &middot; {{ __('Ignored') }} {{ $ignore->created_at->diffForHumans() }}
                                </flux:text>
                            </div>
                        </div>
                        <flux:button
                            wire:click="stopIgnoring({{ $ignore->ignored_user_id }})"
                            wire:loading.attr="disabled"
                            variant="outline"
                            size="sm"
                            class="shrink-0 whitespace-nowrap"
                        >{{ __('Stop ignoring') }}</flux:button>
                    </div>
                @endforeach
            </div>

            @if ($this->ignoredUsers->hasPages())
                <div class="mt-4">
                    {{ $this->ignoredUsers->links() }}
                </div>
            @endif
        @endif
    </x-slot>
</x-action-section>
