<div class="rounded-md bg-gray-900 px-4 py-5 shadow sm:p-6">
    @forelse ($this->bans as $ban)
        <div
            wire:key="issue-ban-{{ $ban->id }}"
            class="flex items-start justify-between gap-4 border-b border-gray-800 py-3 last:border-0"
        >
            <div class="min-w-0">
                <p class="font-medium text-gray-100">{{ $ban->user->name }}</p>
                <p class="text-xs text-gray-400">
                    {{ $ban->expires_at ? __('Until :date', ['date' => $ban->expires_at->toFormattedDayDateString()]) : __('Permanent') }}
                    @if ($ban->bannedBy)
                        · {{ __('by :name', ['name' => $ban->bannedBy->name]) }}
                    @endif
                </p>
                @if ($ban->reason)
                    <p class="mt-1 text-sm text-gray-300">{{ $ban->reason }}</p>
                @endif
            </div>
            <flux:button
                size="sm"
                wire:click="unban({{ $ban->id }})"
                wire:confirm="{{ __('Lift this ban?') }}"
                data-test="issue-unban-{{ $ban->id }}"
            >{{ __('Unban') }}</flux:button>
        </div>
    @empty
        <p class="text-sm text-gray-400">{{ __('Nobody is banned from this mod\'s issues.') }}</p>
    @endforelse
</div>
