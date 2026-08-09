<div>
    <x-slot:header>
        <h2 class="text-xl font-semibold leading-tight text-gray-100">{{ __('Mod Claims') }}</h2>
    </x-slot:header>

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <div class="mb-6 flex items-center justify-between gap-4">
            <p class="text-sm text-gray-400">
                {{ __('Claims escalated for manual review. Confirm the token appears in claim.txt at the root of the linked repository before approving.') }}
            </p>
            <flux:button
                wire:click="$toggle('showAll')"
                variant="outline"
                size="sm"
                icon="funnel"
            >
                {{ $showAll ? __('Show awaiting review') : __('Show all claims') }}
            </flux:button>
        </div>

        <div class="overflow-hidden rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-700">
                    <thead class="bg-gray-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Mod') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Claimant') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Source') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Token') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Requested') }}</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-400">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        @forelse ($this->claims as $claim)
                            <tr wire:key="claim-{{ $claim->id }}" class="hover:bg-gray-800/50">
                                <td class="px-6 py-4">
                                    <a
                                        href="{{ route('mod.show', [$claim->mod->id, $claim->mod->slug]) }}"
                                        class="text-cyan-400 hover:underline"
                                        target="_blank"
                                    >{{ $claim->mod->name }}</a>
                                </td>
                                <td class="px-6 py-4">
                                    <a
                                        href="{{ $claim->user->profile_url }}"
                                        class="text-gray-200 hover:underline"
                                        target="_blank"
                                    >{{ $claim->user->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    @forelse ($claim->mod->sourceCodeLinks as $link)
                                        <a
                                            href="{{ $link->url }}"
                                            class="block truncate text-cyan-400 hover:underline"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >{{ $link->url }}</a>
                                    @empty
                                        <span class="text-gray-500">{{ __('No source link') }}</span>
                                    @endforelse
                                </td>
                                <td class="px-6 py-4">
                                    <span class="font-mono text-xs text-gray-300">{{ $claim->token }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <flux:badge
                                        :color="$claim->status->color()"
                                        :icon="$claim->status->icon()"
                                        size="sm"
                                    >
                                        {{ $claim->status->label() }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-400">
                                    {{ ($claim->escalated_at ?? $claim->created_at)->diffForHumans() }}
                                </td>
                                <td class="px-6 py-4">
                                    @if ($claim->status === \App\Enums\ModClaimStatus::Pending)
                                        <div class="flex justify-end gap-2">
                                            <flux:button
                                                wire:click="approve({{ $claim->id }})"
                                                wire:confirm="{{ __('Assign this mod to the claimant?') }}"
                                                variant="primary"
                                                size="sm"
                                                icon="check"
                                                data-test="claim-approve"
                                            >
                                                {{ __('Approve') }}
                                            </flux:button>
                                            <flux:button
                                                wire:click="reject({{ $claim->id }})"
                                                wire:confirm="{{ __('Reject this claim?') }}"
                                                variant="danger"
                                                size="sm"
                                                icon="x-mark"
                                                data-test="claim-reject"
                                            >
                                                {{ __('Reject') }}
                                            </flux:button>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center">
                                    <div class="flex flex-col items-center justify-center gap-2">
                                        <flux:icon.inbox class="h-12 w-12 text-gray-600" />
                                        <p class="text-gray-400">{{ __('No claims awaiting review') }}</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($this->claims->hasPages())
                <div class="border-t border-gray-700 px-6 py-4">
                    {{ $this->claims->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
