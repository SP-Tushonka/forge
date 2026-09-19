@props(['issue'])

{{-- fix_notified_at doubles as "released": the sweep only sets it once a matching version is public. --}}
@if ($issue->fixed_version !== null)
    @if ($issue->status->isOpen())
        <flux:badge
            size="sm"
            color="zinc"
            icon="flag"
            data-test="issue-fixed-version"
        >{{ __('Target: :version', ['version' => $issue->fixed_version]) }}</flux:badge>
    @elseif ($issue->status === App\Enums\ModIssueStatus::Completed)
        @if ($issue->fix_notified_at !== null)
            <flux:badge
                size="sm"
                color="green"
                icon="check-badge"
                data-test="issue-fixed-version"
            >{{ __('Fixed in :version', ['version' => $issue->fixed_version]) }}</flux:badge>
        @else
            <flux:badge
                size="sm"
                color="amber"
                icon="clock"
                data-test="issue-fixed-version"
            >{{ __('Fix in :version · unreleased', ['version' => $issue->fixed_version]) }}</flux:badge>
        @endif
    @endif
@endif
