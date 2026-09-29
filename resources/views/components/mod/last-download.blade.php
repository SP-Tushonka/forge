@props(['download', 'updateAvailable' => false, 'latestVersion' => null])

@if ($download)
    @if ($updateAvailable)
        <div class="flex items-start gap-2 rounded-lg bg-cyan-950/50 px-3 py-2 text-sm text-cyan-200 ring-1 ring-cyan-800/60">
            <flux:icon.arrow-path class="mt-0.5 size-4 shrink-0" />
            <div>
                <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 font-semibold">
                    {{ __('Update available') }}
                    @if ($latestVersion)
                        &middot; v{{ $latestVersion->version }}
                        @if ($latestVersion->latestSptVersion)
                            <span
                                class="badge-version {{ $latestVersion->latestSptVersion->color_class }} inline-flex items-center text-nowrap rounded px-1.5 py-px text-xs font-medium"
                            >
                                {{ $latestVersion->latestSptVersion->version_formatted }}
                            </span>
                        @endif
                    @endif
                </p>
                <p class="text-xs text-cyan-200/70">
                    {{ __('You have v:version', ['version' => $download->version]) }} &middot;
                    {{ __('downloaded') }} {{ $download->downloaded_at->dynamicFormat() }}
                </p>
            </div>
        </div>
    @else
        <p class="flex items-center gap-1.5 px-1 text-sm text-gray-400">
            <flux:icon.check-circle class="size-4 shrink-0 text-emerald-400" />
            <span>
                {{ __('You downloaded v:version', ['version' => $download->version]) }} &middot;
                {{ $download->downloaded_at->dynamicFormat() }}
            </span>
        </p>
    @endif
@endif
