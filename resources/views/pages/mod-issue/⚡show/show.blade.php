<x-slot:title>
    {{ __(':title - :mod issues - The Forge', ['title' => $issue->getTitle(), 'mod' => $issue->mod->name]) }}
</x-slot>

<x-slot:description>
    {{ Str::limit($issue->body, 150) }}
</x-slot>

<x-slot:header>
    <h2 class="flex items-center gap-2 text-xl font-semibold leading-tight text-gray-200">
        <flux:icon
            :name="$issue->type->icon()"
            class="h-5 w-5"
        />
        {{ __('Issue') }} ·
        <a
            href="{{ $issue->mod->detail_url }}#issues"
            class="hover:underline"
        >{{ $issue->mod->name }}</a>
    </h2>
</x-slot>

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <x-mod-issue.beta-notice class="mb-4" />

    <a
        href="{{ $issue->mod->detail_url }}#issues"
        class="mb-4 inline-block text-sm text-cyan-400 hover:underline"
    >← {{ __('All issues') }}</a>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($issue->trashed())
                <flux:callout
                    icon="trash"
                    color="red"
                    inline
                >
                    <flux:callout.text>
                        {{ __('This issue has been deleted. Only the mod authors and staff can see it.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($issue->isLocked())
                <flux:callout
                    icon="lock-closed"
                    color="zinc"
                    inline
                >
                    <flux:callout.text>
                        {{ __('This issue is locked. Only the mod authors and staff can comment.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($issue->status === App\Enums\ModIssueStatus::Duplicate && $issue->duplicateOf)
                <flux:callout
                    icon="document-duplicate"
                    color="zinc"
                    inline
                >
                    <flux:callout.text>
                        {{ __('Duplicate of') }}
                        <flux:callout.link :href="$issue->duplicateOf->url()">
                            {{ $issue->duplicateOf->getTitle() }}</flux:callout.link>
                    </flux:callout.text>
                </flux:callout>
            @endif

            <article class="rounded-xl bg-gray-950 p-6 shadow-md shadow-gray-950 drop-shadow-2xl">
                @if ($editing)
                    <form
                        wire:submit="saveEdit"
                        class="space-y-4"
                    >
                        <flux:input
                            wire:model="editTitle"
                            :label="__('Title')"
                        />
                        <x-markdown-editor
                            wire-model="editBody"
                            name="editBody"
                            :label="__('Description')"
                            rows="10"
                            purify-config="comments"
                        />
                        <div class="flex justify-end gap-2">
                            <flux:button
                                variant="ghost"
                                wire:click="$set('editing', false)"
                            >{{ __('Cancel') }}</flux:button>
                            <flux:button
                                type="submit"
                                variant="primary"
                            >{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <h1 class="text-2xl font-bold text-white">
                            {{ $issue->title }}
                            <span class="font-normal text-gray-500">#{{ $issue->number }}</span>
                        </h1>
                        @can('update', $issue)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="pencil"
                                wire:click="startEditing"
                                data-test="issue-edit"
                            >{{ __('Edit') }}</flux:button>
                        @endcan
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-gray-400">
                        <x-mod-issue.status-badge :issue="$issue" />
                        <flux:badge
                            size="sm"
                            :icon="$issue->type->icon()"
                        >{{ $issue->type->label() }}</flux:badge>
                        <span>
                            <x-user-name :user="$issue->user" /> {{ __('opened this') }}
                            <x-time :datetime="$issue->created_at" />
                        </span>
                        @if ($issue->edited_at)
                            <span class="italic">({{ __('edited') }})</span>
                        @endif
                    </div>

                    <div class="user-markdown mt-6 text-gray-300">
                        {{-- Cleaned by Purify in ModIssue::bodyHtml. --}}
                        {!! $issue->body_html !!}
                    </div>

                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <x-reaction-bar
                            reactable-type="issue"
                            :reactable-id="$issue->id"
                            :counts="$this->reactionSummary->countsFor($issue->id)"
                            :mine="$this->reactionSummary->mineFor($issue->id)"
                            :whitelist="$this->reactionWhitelist"
                            :can-react="auth()->check() && Gate::allows('react', $issue)"
                        />
                        <livewire:report-component
                            wire:key="issue-report-{{ $issue->id }}"
                            variant="link"
                            :reportable-id="$issue->id"
                            :reportable-type="App\Models\ModIssue::class"
                        />
                    </div>
                @endif
            </article>

            <div id="comments">
                <livewire:comment-component
                    wire:key="issue-comment-component-{{ $issue->id }}"
                    :commentable="$issue"
                />
            </div>
        </div>

        <aside class="space-y-6">
            <section
                class="space-y-3 rounded-xl bg-gray-950 p-4 text-sm shadow-md shadow-gray-950 drop-shadow-2xl">
                <flux:heading>{{ __('Details') }}</flux:heading>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-2">
                    <dt class="text-gray-400">{{ __('Status') }}</dt>
                    <dd><x-mod-issue.status-badge :issue="$issue" /></dd>

                    <dt class="text-gray-400">{{ __('Type') }}</dt>
                    <dd class="text-gray-200">{{ $issue->type->label() }}</dd>

                    @if ($issue->affectedVersion)
                        <dt class="text-gray-400">{{ __('Affected version') }}</dt>
                        <dd class="text-gray-200">v{{ $issue->affectedVersion->version }}</dd>
                    @endif

                    @if ($issue->fixed_version !== null && ($issue->status->isOpen() || $issue->status === App\Enums\ModIssueStatus::Completed))
                        <dt class="text-gray-400">{{ __('Fixed version') }}</dt>
                        <dd>
                            @if ($issue->fix_notified_at !== null)
                                <a href="{{ $issue->mod->detail_url }}#versions">
                                    <x-mod-issue.fixed-version-badge :issue="$issue" />
                                </a>
                            @else
                                <x-mod-issue.fixed-version-badge :issue="$issue" />
                            @endif
                        </dd>
                    @endif
                </dl>
            </section>

            @if (Gate::any(['manage', 'restore'], $issue))
                <livewire:mod-issue.manage-panel
                    wire:key="issue-manage-panel-{{ $issue->id }}"
                    :issue-id="$issue->id"
                />
            @endif

            <div class="space-y-2">
                @can('close', $issue)
                    <flux:button
                        size="sm"
                        icon="check"
                        class="w-full"
                        wire:click="close"
                        wire:confirm="{{ __('Close this issue?') }}"
                        data-test="issue-close"
                    >{{ __('Close issue') }}</flux:button>
                @endcan
                @can('reopen', $issue)
                    <flux:button
                        size="sm"
                        icon="arrow-uturn-left"
                        class="w-full"
                        wire:click="reopen"
                        data-test="issue-reopen"
                    >{{ __('Reopen issue') }}</flux:button>
                @endcan
                @auth
                    <flux:button
                        size="sm"
                        :variant="$this->isSubscribed ? 'primary' : 'outline'"
                        :icon="$this->isSubscribed ? 'bell' : 'bell-slash'"
                        class="w-full"
                        wire:click="toggleSubscription"
                        data-test="issue-subscription-toggle"
                    >{{ $this->isSubscribed ? __('Subscribed') : __('Subscribe') }}</flux:button>
                @endauth
            </div>

            <section
                class="space-y-3 rounded-xl bg-gray-950 p-4 text-sm shadow-md shadow-gray-950 drop-shadow-2xl">
                <flux:heading>{{ __('Activity') }}</flux:heading>
                <ol class="space-y-3">
                    @forelse ($this->events as $event)
                        <li
                            wire:key="issue-event-{{ $event->id }}"
                            class="text-gray-400"
                        >
                            <span class="text-gray-200">{{ $event->user?->name ?? __('The Forge') }}</span>
                            {{ $event->type->describe($event->from, $event->to, $issue->type) }}
                            <span class="block text-xs"><x-time :datetime="$event->created_at" /></span>
                        </li>
                    @empty
                        <li class="text-gray-500">{{ __('No activity yet.') }}</li>
                    @endforelse
                </ol>
            </section>
        </aside>
    </div>

    @if (Gate::allows('manageBans', [App\Models\ModIssue::class, $issue->mod]))
        <livewire:mod-issue.ban-modal
            wire:key="issue-ban-modal-{{ $issue->mod_id }}"
            :mod-id="$issue->mod_id"
        />
    @endif
</div>
