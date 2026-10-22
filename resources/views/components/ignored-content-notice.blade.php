@props(['reason'])

<div
    class="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6 lg:px-8"
    data-test="ignored-content-notice"
>
    <flux:icon.eye-slash class="mx-auto size-12 text-gray-500" />
    <flux:heading
        size="lg"
        class="mt-4"
    >
        @if ($reason->mod)
            {{ __("You're ignoring :mod.", ['mod' => $reason->mod->name]) }}
        @else
            {{ __("You're ignoring content from :user.", ['user' => $reason->user?->name]) }}
        @endif
    </flux:heading>
    <flux:text class="mt-2">{{ __('It is hidden from you everywhere on the site.') }}</flux:text>
    <div class="mt-6 flex justify-center gap-3">
        <flux:button
            wire:click="showIgnoredContent"
            variant="outline"
            data-test="show-ignored-content"
        >{{ __('Show anyway') }}</flux:button>
        <flux:button
            wire:click="stopIgnoring"
            variant="primary"
            data-test="stop-ignoring-content"
        >{{ __('Stop ignoring') }}</flux:button>
    </div>
</div>
