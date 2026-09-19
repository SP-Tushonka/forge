<section
    class="space-y-5 rounded-xl bg-gray-950 p-4 text-sm shadow-md shadow-gray-950 drop-shadow-2xl"
    data-test="issue-manage-panel"
>
    <flux:heading>{{ __('Manage') }}</flux:heading>

    @if ($this->issue->trashed())
        @can('restore', $this->issue)
            <flux:button
                size="sm"
                icon="arrow-path"
                wire:click="restoreIssue"
                data-test="issue-restore"
            >{{ __('Restore issue') }}</flux:button>
        @endcan
    @else
        <form
            wire:submit="saveStatus"
            class="space-y-3"
        >
            <flux:select
                wire:model.live="status"
                variant="listbox"
                :label="__('Status')"
                data-test="issue-status-select"
            >
                @foreach (App\Enums\ModIssueStatus::cases() as $option)
                    <flux:select.option value="{{ $option->value }}">{{ $option->label($this->issue->type) }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            @if ($status === App\Enums\ModIssueStatus::Duplicate->value)
                <flux:select
                    wire:model="duplicateOfId"
                    variant="listbox"
                    searchable
                    :label="__('Duplicate of')"
                    :placeholder="__('Choose an issue')"
                >
                    @foreach ($this->duplicateCandidates as $candidate)
                        <flux:select.option value="{{ $candidate->id }}">#{{ $candidate->number }}
                            {{ Str::limit($candidate->title, 60) }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            <flux:error name="duplicateOfId" />

            <flux:button
                size="sm"
                type="submit"
                variant="primary"
            >{{ __('Update status') }}</flux:button>
        </form>

        <form
            wire:submit="saveDetails"
            class="space-y-3 border-t border-gray-800 pt-4"
        >
            <flux:select
                wire:model="type"
                variant="listbox"
                :label="__('Type')"
            >
                @foreach (App\Enums\ModIssueType::cases() as $issueType)
                    <flux:select.option value="{{ $issueType->value }}">{{ $issueType->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="fixedVersion"
                :label="__('Fixed version')"
                :description="__('The release that will contain the fix, e.g. 1.3.0. It does not have to exist yet.')"
                placeholder="1.3.0"
                data-test="issue-fixed-version-input"
            />

            <flux:button
                size="sm"
                type="submit"
            >{{ __('Save details') }}</flux:button>
        </form>

        <div class="flex flex-wrap gap-2 border-t border-gray-800 pt-4">
            <flux:button
                size="sm"
                :icon="$this->issue->isLocked() ? 'lock-open' : 'lock-closed'"
                wire:click="toggleLock"
                data-test="issue-lock-toggle"
            >{{ $this->issue->isLocked() ? __('Unlock') : __('Lock') }}</flux:button>

            @can('ban', [App\Models\ModIssue::class, $this->issue->mod, $this->issue->user])
                <flux:button
                    size="sm"
                    icon="no-symbol"
                    x-on:click="$dispatch('ban-from-issues', { userId: {{ $this->issue->user_id }} })"
                    data-test="issue-ban-reporter"
                >{{ __('Ban reporter') }}</flux:button>
            @endcan

            <flux:button
                size="sm"
                variant="danger"
                icon="trash"
                wire:click="deleteIssue"
                wire:confirm="{{ __('Delete this issue? Staff can restore it.') }}"
                data-test="issue-delete"
            >{{ __('Delete') }}</flux:button>
        </div>
    @endif
</section>
