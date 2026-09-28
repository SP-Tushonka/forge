<div>
    <x-slot name="header">
        <div class="flex w-full items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-gray-200">
                {{ __('Alt Monitoring') }}
            </h2>
            <div class="flex shrink-0 items-center gap-2">
                <flux:button
                    href="{{ route('admin.alt-detection') }}"
                    wire:navigate
                    variant="outline"
                    size="sm"
                    icon="magnifying-glass"
                >
                    Investigate a user
                </flux:button>
                <flux:button
                    href="{{ route('admin.alt-monitoring.watches.create') }}"
                    wire:navigate
                    variant="primary"
                    size="sm"
                    icon="plus"
                >
                    New watch
                </flux:button>
            </div>
        </div>
    </x-slot>

    <div class="px-6 py-6 lg:px-8">
        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach ([['Active watches', $this->stats['active']], ['Unreviewed matches', $this->stats['unreviewed']], ['Matches in the last 7 days', $this->stats['recent']]] as [$label, $value])
                    <div class="rounded-lg border border-gray-700 bg-gray-900 p-4 shadow-sm">
                        <div class="text-xs uppercase tracking-wide text-gray-500">{{ $label }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-100">{{ number_format($value) }}</div>
                    </div>
                @endforeach
            </div>

            <section class="rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                <h3 class="border-b border-gray-700 px-6 py-4 text-sm font-semibold text-gray-100">Unreviewed matches</h3>
                @forelse ($this->unreviewedMatches as $match)
                    <div
                        wire:key="match-{{ $match->id }}"
                        class="flex flex-col gap-3 border-b border-gray-800 px-6 py-4 last:border-b-0 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 text-sm">
                            <a
                                href="{{ $match->user->profile_url }}"
                                target="_blank"
                                class="font-medium text-gray-100 underline hover:text-gray-300"
                            >{{ $match->user->name }}</a>
                            <span class="text-gray-400">matched the watch on</span>
                            <a
                                href="{{ route('admin.alt-monitoring.watches.show', $match->alt_watch_id) }}"
                                wire:navigate
                                class="font-medium text-gray-100 underline hover:text-gray-300"
                            >{{ $match->watch->watchedName() }}</a>
                            <div class="mt-1 text-xs text-gray-400">
                                {{ implode(', ', $match->matchedKinds()) }} · {{ $match->first_matched_at->diffForHumans() }}
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <flux:button
                                href="{{ route('admin.alt-detection', $match->user_id) }}"
                                wire:navigate
                                size="sm"
                                variant="outline"
                                icon="finger-print"
                            >
                                Investigate
                            </flux:button>
                            <flux:button
                                wire:click="review({{ $match->id }}, 'confirmed')"
                                size="sm"
                                variant="danger"
                            >
                                Confirm alt
                            </flux:button>
                            <flux:button
                                wire:click="review({{ $match->id }}, 'dismissed')"
                                size="sm"
                                variant="ghost"
                            >
                                Dismiss
                            </flux:button>
                        </div>
                    </div>
                @empty
                    <p class="px-6 py-6 text-sm text-gray-400">No matches waiting for review.</p>
                @endforelse
            </section>

            <section class="rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                <div class="flex items-center justify-between gap-4 border-b border-gray-700 px-6 py-4">
                    <h3 class="text-sm font-semibold text-gray-100">Watches</h3>
                    <flux:select
                        wire:model.live="filter"
                        size="sm"
                        class="max-w-40"
                    >
                        <flux:select.option value="active">Active</flux:select.option>
                        <flux:select.option value="all">All</flux:select.option>
                    </flux:select>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Watched user</th>
                                <th class="px-6 py-3">Reason</th>
                                <th class="px-6 py-3">Mode</th>
                                <th class="px-6 py-3">Indicators</th>
                                <th class="px-6 py-3">Created by</th>
                                <th class="px-6 py-3">Expires</th>
                                <th class="px-6 py-3">Matches</th>
                                <th class="px-6 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800 text-gray-200">
                            @forelse ($this->watches as $watch)
                                <tr wire:key="watch-{{ $watch->id }}">
                                    <td class="px-6 py-3">
                                        <a
                                            href="{{ route('admin.alt-monitoring.watches.show', $watch) }}"
                                            wire:navigate
                                            class="font-medium underline hover:text-gray-300"
                                        >{{ $watch->watchedName() }}</a>
                                    </td>
                                    <td
                                        class="max-w-xs truncate px-6 py-3 text-gray-400"
                                        title="{{ $watch->reason }}"
                                    >{{ $watch->reason }}</td>
                                    <td class="px-6 py-3">{{ $watch->match_mode->label() }}</td>
                                    <td class="px-6 py-3">{{ $watch->indicators_count }}</td>
                                    <td class="px-6 py-3">{{ $watch->creator?->name ?? 'Deleted account' }}</td>
                                    <td class="px-6 py-3">{{ $watch->expires_at->toFormattedDateString() }}</td>
                                    <td class="px-6 py-3">{{ $watch->matches_count }}</td>
                                    <td class="px-6 py-3">
                                        <flux:badge
                                            size="sm"
                                            :color="$watch->statusColor()"
                                        >{{ ucfirst($watch->status()) }}</flux:badge>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td
                                        colspan="8"
                                        class="px-6 py-6 text-sm text-gray-400"
                                    >No watches to show.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</div>
