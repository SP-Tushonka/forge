@props(['mod' => null, 'show' => null, 'tooltip' => null])

@if ($show ?? ($mod !== null && App\Support\IgnoredContent::isHidden($mod, auth()->user())))
    <flux:tooltip content="{{ $tooltip ?? __('You are ignoring this. It is still listed because it is required here.') }}">
        <flux:badge
            size="sm"
            color="zinc"
            icon="eye-slash"
            data-test="ignored-badge"
        >{{ __('Ignored') }}</flux:badge>
    </flux:tooltip>
@endif
