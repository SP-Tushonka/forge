<div class="flex flex-col gap-6">
    {{-- Search --}}
    <flux:field>
        <flux:label>{{ __('Find a mod') }}</flux:label>
        <flux:description>{{ __('Search by name, slug, GUID, mod ID or owner name.') }}</flux:description>
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search…') }}"
        />
    </flux:field>

    @if ($this->results->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Mod') }}</flux:table.column>
                <flux:table.column>{{ __('Owner') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Select') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->results as $result)
                    <flux:table.row :key="'mod-result-' . $result->id">
                        <flux:table.cell>
                            {{ $result->name }}
                            <span class="text-zinc-400">#{{ $result->id }}</span>
                        </flux:table.cell>
                        <flux:table.cell>{{ $result->owner?->name ?? __('Unowned') }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button
                                size="sm"
                                wire:click="selectMod({{ $result->id }})"
                            >{{ __('Select') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Summary card --}}
    @if ($this->target !== null)
        @php($mod = $this->target)
        <flux:card class="flex flex-col gap-3">
            <div class="flex items-start justify-between">
                <div>
                    <flux:heading size="lg">
                        {{ $mod->name }}
                        <span class="text-zinc-400">#{{ $mod->id }}</span>
                    </flux:heading>
                    <flux:subheading>
                        {{ $mod->owner?->name ?? __('Unowned') }} ·
                        {{ $mod->category?->title ?? __('No category') }} ·
                        {{ $mod->license?->name ?? __('No license') }}
                    </flux:subheading>
                </div>
                <flux:button
                    size="sm"
                    variant="ghost"
                    wire:click="clearMod"
                >{{ __('Clear') }}</flux:button>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($mod->disabled)
                    <flux:badge color="red">{{ __('Disabled') }}</flux:badge>
                @endif
                @if ($mod->published_at === null)
                    <flux:badge color="amber">{{ __('Unpublished') }}</flux:badge>
                @endif
                @if ($mod->featured)
                    <flux:badge color="yellow">{{ __('Featured') }}</flux:badge>
                @endif
                @if ($mod->contains_ai_content)
                    <flux:badge color="purple">{{ __('AI content') }}</flux:badge>
                @endif
            </div>

            <dl class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-zinc-400">{{ __('GUID') }}</dt>
                    <dd class="font-mono text-xs">{{ $mod->guid ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-400">{{ __('Slug') }}</dt>
                    <dd class="font-mono text-xs">{{ $mod->slug }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-400">{{ __('Versions') }}</dt>
                    <dd>{{ $mod->versions_count }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-400">{{ __('Downloads') }}</dt>
                    <dd>{{ number_format($mod->downloads) }}</dd>
                </div>
            </dl>
        </flux:card>

        {{-- Details --}}
        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Details') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" />
            <flux:input wire:model="guid" :label="__('GUID')" />
            <flux:input wire:model="teaser" :label="__('Summary')" />
            <flux:textarea wire:model="description" :label="__('Description')" rows="10" />

            <flux:select wire:model="category" :label="__('Category')">
                @foreach ($this->categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->title }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="license" :label="__('License')">
                @foreach ($this->licenses as $license)
                    <flux:select.option value="{{ $license->id }}">{{ $license->name }}</flux:select.option>
                @endforeach
            </flux:select>

            {{-- Keyed on the mod so switching targets remounts the picker; selectedUsers is a
                 mount-time prop, not reactive, and would otherwise keep the previous mod's authors. --}}
            <livewire:form.user-select
                :key="'mod-tool-authors-' . $mod->id"
                :selected-users="$authorIds"
                :max-users="10"
                :exclude-users="$mod->owner_id !== null ? [$mod->owner_id] : []"
                :show-user-id="true"
                label="Additional Authors"
                description="Co-authors of this mod. The owner is not listed here and cannot be removed."
                placeholder="Search for users by name or email..."
            />

            <flux:error name="authorIds" />
            @foreach ($authorIds as $index => $authorId)
                <flux:error name="authorIds.{{ $index }}" />
            @endforeach

            <div class="flex flex-col gap-2">
                <flux:checkbox wire:model="containsAds" :label="__('Contains ads')" />
                <flux:checkbox wire:model="commentsDisabled" :label="__('Comments disabled')" />
                <flux:checkbox wire:model="addonsDisabled" :label="__('Addons disabled')" />
                <flux:checkbox wire:model="listsDisabled" :label="__('Lists disabled')" />
                <flux:checkbox wire:model="cheatNotice" :label="__('Show cheat notice')" />
                <flux:checkbox wire:model="disableProfileBindingNotice" :label="__('Hide profile binding notice')" />
                @if ($this->canLockAiContent)
                    <flux:checkbox wire:model="containsAiContentLocked" :label="__('Lock the AI content flag')" />
                @endif
                <flux:checkbox wire:model="containsAiContent" :label="__('Contains AI content')" />
            </div>

            <flux:textarea wire:model="customAiDisclosure" :label="__('AI disclosure')" rows="3" />

            <flux:textarea
                wire:model="reason"
                :label="__('Reason for this edit')"
                :description="__('Recorded on the moderation log. Required.')"
                rows="2"
            />

            <div>
                <flux:button variant="primary" wire:click="saveDetails">{{ __('Save details') }}</flux:button>
            </div>
        </flux:card>

        {{-- Ownership --}}
        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Ownership') }}</flux:heading>

            <flux:subheading>
                {{ __('Current owner') }}: {{ $mod->owner?->name ?? __('Unowned') }}
            </flux:subheading>

            <flux:field>
                <flux:label>{{ __('Transfer to') }}</flux:label>
                <flux:input
                    wire:model.live.debounce.300ms="ownerSearch"
                    placeholder="{{ __('Search by name, email or user ID…') }}"
                />
            </flux:field>

            @if ($this->ownerResults->isNotEmpty())
                <flux:table>
                    <flux:table.rows>
                        @foreach ($this->ownerResults as $candidate)
                            <flux:table.row :key="'owner-' . $candidate->id">
                                <flux:table.cell>
                                    {{ $candidate->name }}
                                    <span class="text-zinc-400">#{{ $candidate->id }}</span>
                                </flux:table.cell>
                                <flux:table.cell align="end">
                                    <flux:button
                                        size="sm"
                                        wire:click="selectNewOwner({{ $candidate->id }})"
                                    >{{ __('Choose') }}</flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif

            @if ($this->newOwnerId !== null)
                <flux:badge color="blue">{{ __('New owner selected') }}: #{{ $this->newOwnerId }}</flux:badge>
            @endif

            <flux:checkbox
                wire:model="keepPreviousAsAuthor"
                :label="__('Keep the previous owner as an additional author')"
            />

            <flux:callout variant="warning">
                {{ __('Clearing the owner returns this mod to the claimable pool. Anyone able to prove ownership can claim it.') }}
            </flux:callout>

            <flux:textarea
                wire:model="ownershipReason"
                :label="__('Reason for this ownership change')"
                :description="__('Recorded on the moderation log and emailed to the affected users. Required.')"
                rows="2"
            />

            <flux:error name="newOwnerId" />
            <flux:error name="action" />

            <div class="flex gap-2">
                <flux:button variant="primary" wire:click="transferOwnership">{{ __('Transfer ownership') }}</flux:button>
                <flux:button variant="danger" wire:click="clearOwnership">{{ __('Clear owner') }}</flux:button>
            </div>
        </flux:card>

        {{-- Versions --}}
        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Versions') }}</flux:heading>

            @if ($mod->versions->isEmpty())
                <flux:subheading>{{ __('This mod has no versions.') }}</flux:subheading>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Version') }}</flux:table.column>
                        <flux:table.column>{{ __('SPT constraint') }}</flux:table.column>
                        <flux:table.column>{{ __('State') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Downloads') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($mod->versions as $version)
                            <flux:table.row :key="'version-' . $version->id">
                                <flux:table.cell class="font-mono text-xs">{{ $version->version }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $version->spt_version_constraint }}</flux:table.cell>
                                {{-- Always renders a publish state, so the column is never blank. --}}
                                <flux:table.cell>
                                    <div class="flex flex-wrap gap-1">
                                        @if ($version->published_at === null)
                                            <flux:badge color="amber">{{ __('Unpublished') }}</flux:badge>
                                        @elseif ($version->published_at->isFuture())
                                            <flux:badge color="blue">{{ __('Scheduled') }}</flux:badge>
                                        @else
                                            <flux:badge color="green">{{ __('Published') }}</flux:badge>
                                        @endif

                                        @if ($version->disabled)
                                            <flux:badge color="red">{{ __('Disabled') }}</flux:badge>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="end">{{ number_format($version->downloads) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <div class="flex justify-end gap-2">
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            href="{{ route('mod.version.edit', ['mod' => $mod->id, 'modVersion' => $version->id]) }}"
                                        >{{ __('Edit') }}</flux:button>
                                        <livewire:mod.version-action
                                            :key="'version-action-' . $version->id"
                                            :versionId="$version->id"
                                            :modId="$mod->id"
                                            :versionNumber="$version->version"
                                            :versionDisabled="$version->disabled"
                                            :versionPublished="$version->published_at !== null"
                                        />
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </flux:card>
    @endif
</div>
