<x-slot:title>
    {{ __('New issue - :mod - The Forge', ['mod' => $mod->name]) }}
</x-slot>

<x-slot:description>
    {{ __('Report a bug or request a feature for :mod.', ['mod' => $mod->name]) }}
</x-slot>

<x-slot:header>
    <h2 class="text-xl font-semibold leading-tight text-gray-100">
        {{ __('New issue') }} ·
        <a
            href="{{ $mod->detail_url }}#issues"
            class="hover:underline"
        >{{ $mod->name }}</a>
    </h2>
</x-slot>

<div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
    <x-mod-issue.beta-notice class="mb-4" />

    {{-- The markdown editor blocks pasted log files; the form refuses to submit while one is detected. --}}
    <form
        x-data="{ hasLogFile: false }"
        @log-file-detected.window="hasLogFile = $event.detail.containsLogFile"
        @submit.prevent="!hasLogFile && $wire.save()"
        class="space-y-6 rounded-xl bg-gray-950 p-6 shadow-md shadow-gray-950 drop-shadow-2xl"
    >
        <x-honeypot livewire-model="honeypotData" />

        <flux:radio.group
            wire:model.live="type"
            :label="__('Type')"
            variant="segmented"
        >
            @foreach (App\Enums\ModIssueType::cases() as $issueType)
                <flux:radio
                    value="{{ $issueType->value }}"
                    :label="$issueType->label()"
                    :icon="$issueType->icon()"
                />
            @endforeach
        </flux:radio.group>

        <flux:input
            wire:model="title"
            :label="__('Title')"
            maxlength="{{ config('mod-issues.validation.title_max') }}"
            data-test="issue-title"
        />

        @if ($type === App\Enums\ModIssueType::Bug->value)
            <flux:select
                wire:model="affectedVersionId"
                variant="listbox"
                :label="__('Affected version')"
                :description="__('The version you found the bug in.')"
                data-test="issue-affected-version"
            >
                @foreach ($this->affectedVersions as $version)
                    <flux:select.option value="{{ $version->id }}">v{{ $version->version }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif

        <x-markdown-editor
            wire-model="body"
            name="body"
            :label="__('Description')"
            rows="12"
            purify-config="comments"
            data-test="issue-body"
        />

        <div class="flex justify-end gap-2">
            <flux:button
                variant="ghost"
                :href="$mod->detail_url . '#issues'"
            >{{ __('Cancel') }}</flux:button>
            <flux:button
                type="submit"
                variant="primary"
                ::disabled="hasLogFile"
                wire:loading.attr="disabled"
                wire:target="save"
                data-test="issue-submit"
            >{{ __('Open issue') }}</flux:button>
        </div>
    </form>
</div>
