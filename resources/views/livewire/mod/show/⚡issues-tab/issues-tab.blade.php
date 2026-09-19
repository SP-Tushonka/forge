@placeholder
    <div class="space-y-4">
        @for ($i = 0; $i < 4; $i++)
            <div class="rounded-xl bg-gray-950 p-4 shadow-md shadow-gray-950 drop-shadow-2xl sm:p-6">
                <flux:skeleton.group
                    animate="shimmer"
                    class="space-y-2"
                >
                    <flux:skeleton.line class="w-2/3" />
                    <flux:skeleton.line class="w-1/3" />
                </flux:skeleton.group>
            </div>
        @endfor
    </div>
@endplaceholder

<div
    id="issues"
    class="space-y-4"
>
    <x-mod-issue.beta-notice />

    @unless ($this->mod->issues_enabled)
        <flux:callout
            icon="exclamation-triangle"
            color="orange"
            inline
        >
            <flux:callout.text>
                {{ __('Issues are switched off for this mod, so only its authors and staff can see this tab. Existing issues are kept.') }}
            </flux:callout.text>
        </flux:callout>
    @endunless

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <flux:button
                size="sm"
                :variant="$state === 'open' ? 'primary' : 'ghost'"
                wire:click="$set('state', 'open')"
                data-test="issues-open-toggle"
            >{{ __('Open') }} ({{ $this->openCount }})</flux:button>
            <flux:button
                size="sm"
                :variant="$state === 'closed' ? 'primary' : 'ghost'"
                wire:click="$set('state', 'closed')"
                data-test="issues-closed-toggle"
            >{{ __('Closed') }} ({{ $this->closedCount }})</flux:button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @auth
                <flux:button
                    size="sm"
                    variant="ghost"
                    :icon="$this->isModMuted ? 'bell-slash' : 'bell'"
                    wire:click="toggleModMute"
                    data-test="issues-mute-toggle"
                >{{ $this->isModMuted ? __('Unmute issues') : __('Mute issues') }}</flux:button>

                @if ($this->createResponse->allowed())
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="plus"
                        :href="route('mod.issue.create', ['modId' => $this->mod->id, 'slug' => $this->mod->slug])"
                        data-test="new-issue-button"
                    >{{ __('New issue') }}</flux:button>
                @else
                    <flux:text class="text-sm">{{ $this->createResponse->message() }}</flux:text>
                @endif
            @else
                <flux:button
                    size="sm"
                    :href="route('login')"
                >{{ __('Log in to open an issue') }}</flux:button>
            @endauth
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-5">
        <flux:input
            size="sm"
            icon="magnifying-glass"
            wire:model.live.debounce.400ms="search"
            :placeholder="__('Search titles')"
            class="sm:col-span-2"
        />
        <flux:select
            size="sm"
            wire:model.live="type"
        >
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (App\Enums\ModIssueType::cases() as $issueType)
                <flux:select.option value="{{ $issueType->value }}">{{ $issueType->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select
            size="sm"
            wire:model.live="status"
        >
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach ($this->statusOptions as $option)
                <flux:select.option value="{{ $option->value }}">
                    {{ $option->label(App\Enums\ModIssueType::Feature) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select
            size="sm"
            wire:model.live="sort"
        >
            <flux:select.option value="newest">{{ __('Newest') }}</flux:select.option>
            <flux:select.option value="updated">{{ __('Recently updated') }}</flux:select.option>
            <flux:select.option value="reactions">{{ __('Most reactions') }}</flux:select.option>
        </flux:select>
    </div>

    <div
        class="divide-y divide-gray-800 overflow-hidden rounded-xl bg-gray-950 shadow-md shadow-gray-950 drop-shadow-2xl">
        @forelse ($this->issues as $issue)
            <a
                href="{{ $issue->url() }}"
                wire:key="issue-row-{{ $issue->id }}"
                class="flex items-start gap-3 p-4 hover:bg-black sm:px-6"
                data-test="issue-row-{{ $issue->number }}"
            >
                <flux:icon
                    :name="$issue->type->icon()"
                    class="mt-0.5 size-5 shrink-0 text-gray-400"
                />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium text-white">{{ $issue->title }}</span>
                        <x-mod-issue.status-badge :issue="$issue" />
                        <x-mod-issue.fixed-version-badge :issue="$issue" />
                    </div>
                    <p class="mt-1 text-xs text-gray-400">
                        #{{ $issue->number }} · {{ __('opened by') }} {{ $issue->user->name }} ·
                        <x-time :datetime="$issue->created_at" />
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-3 text-xs text-gray-400">
                    <span class="inline-flex items-center gap-1">
                        <flux:icon.chat-bubble-left class="size-4" /> {{ $issue->comments_count }}
                    </span>
                    <span class="inline-flex items-center gap-1">
                        <flux:icon.face-smile class="size-4" /> {{ $issue->reactions_count }}
                    </span>
                </div>
            </a>
        @empty
            <div class="py-10 text-center">
                <flux:icon.bug-ant class="mx-auto size-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-100">
                    {{ $state === 'open' ? __('No open issues') : __('No closed issues') }}
                </h3>
            </div>
        @endforelse
    </div>

    {{ $this->issues->links() }}
</div>
