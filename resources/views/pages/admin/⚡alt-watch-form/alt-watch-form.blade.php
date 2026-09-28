<div>
    <x-slot name="header">
        <div class="flex w-full items-center justify-between gap-4">
            <h2 class="text-xl font-semibold leading-tight text-gray-200">
                {{ $watchId === null ? __('New Alt Watch') : __('Edit Alt Watch') }}
            </h2>
            <flux:button
                href="{{ $watchId === null ? route('admin.alt-monitoring') : route('admin.alt-monitoring.watches.show', $watchId) }}"
                wire:navigate
                variant="ghost"
                size="sm"
                icon="arrow-uturn-left"
            >
                Back
            </flux:button>
        </div>
    </x-slot>

    <div class="px-6 py-6 lg:px-8">
        <div class="space-y-6">
            @if ($watchId === null && $watchedUserId === null)
                <div class="rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        label="Find a user to watch"
                        placeholder="Name, email, or ID..."
                        icon="magnifying-glass"
                    />

                    @if (mb_strlen(trim($search)) >= 2)
                        <div class="mt-4 divide-y divide-gray-700">
                            @forelse ($this->searchResults as $user)
                                <button
                                    type="button"
                                    wire:key="result-{{ $user->id }}"
                                    wire:click="selectUser({{ $user->id }})"
                                    class="-mx-2 flex w-full items-center gap-3 rounded-md px-2 py-3 text-left hover:bg-gray-800"
                                >
                                    <flux:avatar
                                        circle="circle"
                                        src="{{ $user->profile_photo_url }}"
                                        color="auto"
                                        color:seed="{{ $user->id }}"
                                        size="sm"
                                    />
                                    <div class="min-w-0">
                                        <div class="truncate text-sm font-medium text-gray-100">{{ $user->name }}</div>
                                        <div class="truncate text-xs text-gray-400">{{ $user->email }} · ID {{ $user->id }}</div>
                                    </div>
                                </button>
                            @empty
                                <p class="py-3 text-sm text-gray-400">No users found.</p>
                            @endforelse
                        </div>
                    @endif
                </div>
            @else
                <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                    <div class="min-w-0">
                        <div class="text-xs uppercase tracking-wide text-gray-500">Watched user</div>
                        @if ($this->watchedUser)
                            <a
                                href="{{ $this->watchedUser->profile_url }}"
                                target="_blank"
                                class="text-base font-semibold text-gray-100 underline hover:text-gray-300"
                            >{{ $this->watchedUser->name }}</a>
                            <div class="text-xs text-gray-400">{{ $this->watchedUser->email }} · ID {{ $this->watchedUser->id }}</div>
                        @else
                            <div class="text-base font-semibold text-gray-100">{{ $this->watch?->watched_user_name }}</div>
                            <div class="text-xs text-gray-400">Account deleted</div>
                        @endif
                    </div>
                    @if ($watchId === null)
                        <flux:button
                            wire:click="clearUser"
                            variant="outline"
                            size="sm"
                            icon="arrow-path"
                        >
                            Change user
                        </flux:button>
                    @endif
                </div>

                <form
                    wire:submit="save"
                    class="space-y-6"
                >
                    <div class="rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                        <flux:checkbox.group
                            wire:model.live="selected"
                            label="Indicators to watch"
                            description="Identifiers (devices, IP addresses, IP ranges, email domain) find accounts. Qualifiers (user agent, browser print, country) only narrow a match."
                        >
                            @forelse ($this->groupedIndicators() as $group => $indicators)
                                <div class="mb-2 mt-5 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    {{ $group }} · {{ $indicators[0]->type->isIdentifier() ? 'identifier' : 'qualifier' }}
                                </div>
                                @foreach ($indicators as $indicator)
                                    <flux:checkbox
                                        wire:key="indicator-{{ $indicator->key() }}"
                                        value="{{ $indicator->key() }}"
                                        label="{{ $indicator->label }}"
                                        description="{{ $this->describe($indicator) }}"
                                    />
                                @endforeach
                            @empty
                                <p class="mt-3 text-sm text-gray-400">No activity is recorded for this user yet, so there is nothing to watch.</p>
                            @endforelse
                        </flux:checkbox.group>
                    </div>

                    <div class="grid grid-cols-1 gap-6 rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm lg:grid-cols-2">
                        <flux:radio.group
                            wire:model.live="mode"
                            label="Match when"
                            description="Every ticked qualifier must also match."
                        >
                            <flux:radio
                                value="any"
                                label="Any ticked identifier matches"
                            />
                            <flux:radio
                                value="all"
                                label="All ticked identifiers match"
                            />
                        </flux:radio.group>

                        <flux:select
                            wire:model="days"
                            label="Expires"
                        >
                            @if ($watchId !== null)
                                <flux:select.option value="">Keep the current expiry ({{ $this->watch?->expires_at->toFormattedDateString() }})</flux:select.option>
                            @endif
                            @foreach ($this->expiryOptions() as $option)
                                <flux:select.option value="{{ $option }}">{{ $option }} days from now</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="lg:col-span-2">
                            <flux:textarea
                                wire:model="reason"
                                label="Reason"
                                description="Why this account is being watched. Every admin sees it on each alert, and it is included in alert emails, so do not paste IP addresses or email addresses."
                                rows="2"
                            />
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-700 bg-gray-900 p-6 shadow-sm">
                        <h3 class="text-sm font-semibold text-gray-100">Accounts matching today</h3>
                        @if ($this->preview === null)
                            <p class="mt-2 text-sm text-gray-400">Tick at least one identifier to see who matches.</p>
                        @elseif ($this->preview->tooBroad())
                            <flux:callout
                                class="mt-3"
                                variant="danger"
                                icon="exclamation-triangle"
                                heading="This watch matches too many accounts to be useful. Narrow it down."
                            />
                        @elseif ($this->preview->count() === 0)
                            <p class="mt-2 text-sm text-gray-400">No other account matches today.</p>
                        @else
                            <p class="mt-2 text-sm text-gray-400">
                                {{ trans_choice(':count account matches|:count accounts match', $this->preview->count(), ['count' => $this->preview->count()]) }}
                                today. Saving records them as already matching, without an alert.
                            </p>
                            <ul class="mt-3 flex flex-wrap gap-2">
                                @foreach ($this->previewAccounts as $account)
                                    <li wire:key="preview-{{ $account->id }}">
                                        <a
                                            href="{{ $account->profile_url }}"
                                            target="_blank"
                                            class="rounded bg-gray-800 px-2 py-1 text-xs text-gray-200 hover:bg-gray-700"
                                        >{{ $account->name }}</a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    <div class="flex justify-end">
                        <flux:button
                            type="submit"
                            variant="primary"
                            icon="eye"
                        >
                            Save watch
                        </flux:button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
