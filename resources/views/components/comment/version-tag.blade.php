@props(['comment', 'latest', 'color'])

@php
    $version = $comment->commentable_version;
    $tooltip =
        $latest !== null && $latest !== $version
            ? __('Written for v:version, latest is v:latest', ['version' => $version, 'latest' => $latest])
            : __('Written for v:version', ['version' => $version]);
@endphp

<flux:tooltip :content="$tooltip">
    <flux:badge
        size="sm"
        :color="$color->badgeColor()"
        class="max-w-40"
        data-test="comment-version-tag-{{ $comment->id }}"
        data-color="{{ $color->value }}"
    ><span class="min-w-0 truncate">v{{ $version }}</span></flux:badge>
</flux:tooltip>
