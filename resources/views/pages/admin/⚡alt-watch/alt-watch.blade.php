<div>
    <x-slot name="header">
        <div class="flex w-full items-center justify-between gap-4">
            <h2 class="min-w-0 truncate text-xl font-semibold leading-tight text-gray-200">
                {{ __('Watch on :name', ['name' => $watch->watchedName()]) }}
            </h2>
            <div class="flex shrink-0 items-center gap-2">
                <flux:button
                    href="{{ route('admin.alt-monitoring') }}"
                    wire:navigate
                    variant="ghost"
                    size="sm"
                    icon="arrow-uturn-left"
                >
                    Alt Monitoring
                </flux:button>
                @if ($watch->ended_at === null)
                    <flux:button
                        href="{{ route('admin.alt-monitoring.watches.edit', $watch) }}"
                        wire:navigate
                        variant="outline"
                        size="sm"
                        icon="pencil-square"
                    >
                        Edit
                    </flux:button>
                    <flux:button
                        wire:click="endWatch"
                        wire:confirm="End this watch? It stops matching now and is deleted after 30 days."
                        variant="danger"
                        size="sm"
                        icon="stop-circle"
                    >
                        End watch
                    </flux:button>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="px-6 py-6 lg:px-8">
        <div class="space-y-6">
            <section class="rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Watched user</dt>
                        <dd class="mt-1 text-gray-100">
                            @if ($watch->watchedUser)
                                <a
                                    href="{{ $watch->watchedUser->profile_url }}"
                                    target="_blank"
                                    class="underline hover:text-gray-300"
                                >{{ $watch->watchedUser->name }}</a>
                            @else
                                {{ $watch->watched_user_name }} <span class="text-gray-500">(account deleted)</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Status</dt>
                        <dd class="mt-1">
                            <flux:badge
                                size="sm"
                                :color="$watch->statusColor()"
                            >{{ ucfirst($watch->status()) }}</flux:badge>
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Reason</dt>
                        <dd class="mt-1 whitespace-pre-line text-gray-100">{{ $watch->reason }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Match when</dt>
                        <dd class="mt-1 text-gray-100">{{ $watch->match_mode->label() }}, plus every qualifier</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Expires</dt>
                        <dd class="mt-1 text-gray-100">{{ $watch->expires_at->toDayDateTimeString() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-gray-500">Created</dt>
                        <dd class="mt-1 text-gray-100">
                            {{ $watch->created_at->toDayDateTimeString() }} by {{ $watch->creator?->name ?? 'a deleted account' }}
                        </dd>
                    </div>
                    @if ($watch->ended_at)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Ended</dt>
                            <dd class="mt-1 text-gray-100">
                                {{ $watch->ended_at->toDayDateTimeString() }} by {{ $watch->ender?->name ?? 'a deleted account' }}
                            </dd>
                        </div>
                    @endif
                </dl>
            </section>

            <section class="rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                <h3 class="border-b border-gray-700 px-6 py-4 text-sm font-semibold text-gray-100">Indicators</h3>
                <ul class="divide-y divide-gray-800">
                    @foreach ($watch->indicators as $indicator)
                        <li
                            wire:key="indicator-{{ $indicator->id }}"
                            class="flex items-center justify-between gap-4 px-6 py-3 text-sm"
                        >
                            <span
                                class="min-w-0 truncate text-gray-100"
                                title="{{ $indicator->value }}"
                            >{{ $indicator->label ?? $indicator->value }}</span>
                            <span class="shrink-0 text-xs text-gray-400">
                                {{ $indicator->type->label() }} · {{ $indicator->type->isIdentifier() ? 'identifier' : 'qualifier' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                <h3 class="border-b border-gray-700 px-6 py-4 text-sm font-semibold text-gray-100">Matches</h3>
                @forelse ($this->newMatches as $match)
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
                            <div class="mt-1 text-xs text-gray-400">
                                {{ implode(', ', $match->matchedKinds()) }} · first matched
                                {{ $match->first_matched_at->diffForHumans() }} · last matched
                                {{ $match->last_matched_at->diffForHumans() }}
                                @if ($match->review_outcome)
                                    · {{ $match->review_outcome->label() }} by
                                    {{ $match->reviewer?->name ?? 'a deleted account' }}
                                @endif
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
                            @if ($match->review_outcome === null)
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
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="px-6 py-6 text-sm text-gray-400">No account has matched since the watch was saved.</p>
                @endforelse
            </section>

            <section class="rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                <h3 class="border-b border-gray-700 px-6 py-4 text-sm font-semibold text-gray-100">Already matching when saved</h3>
                @forelse ($this->baselineMatches as $match)
                    <div
                        wire:key="baseline-{{ $match->id }}"
                        class="flex items-center justify-between gap-4 border-b border-gray-800 px-6 py-3 text-sm last:border-b-0"
                    >
                        <a
                            href="{{ $match->user->profile_url }}"
                            target="_blank"
                            class="text-gray-100 underline hover:text-gray-300"
                        >{{ $match->user->name }}</a>
                        <span class="text-xs text-gray-400">{{ implode(', ', $match->matchedKinds()) }}</span>
                    </div>
                @empty
                    <p class="px-6 py-6 text-sm text-gray-400">No account matched when the watch was saved.</p>
                @endforelse
            </section>
        </div>
    </div>
</div>
