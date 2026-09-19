@props(['issue'])

<flux:badge
    size="sm"
    :color="$issue->status->badgeColor()"
    data-test="issue-status"
>{{ $issue->status->label($issue->type) }}</flux:badge>
