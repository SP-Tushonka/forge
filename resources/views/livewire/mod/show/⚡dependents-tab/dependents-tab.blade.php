@placeholder
    <div class="rounded-xl bg-gray-950 p-4 shadow-md shadow-gray-950 drop-shadow-2xl sm:p-6">
        <flux:skeleton.group
            animate="shimmer"
            class="divide-y divide-gray-800"
        >
            @for ($i = 0; $i < 5; $i++)
                <div class="flex items-center gap-3 py-3 first:pt-0 last:pb-0">
                    <flux:skeleton class="size-12 shrink-0 rounded-lg" />
                    <div class="flex-1 space-y-2">
                        <flux:skeleton.line class="w-1/2" />
                        <flux:skeleton.line class="w-1/4" />
                    </div>
                </div>
            @endfor
        </flux:skeleton.group>
    </div>
@endplaceholder

<div id="dependents">
    @if ($this->dependents->count())
        <div class="rounded-xl bg-gray-950 p-4 shadow-md shadow-gray-950 drop-shadow-2xl sm:p-6">
            <ul
                role="list"
                class="divide-y divide-gray-800"
            >
                @foreach ($this->dependents as $dependent)
                    <li
                        wire:key="mod-dependent-{{ $dependent->id }}"
                        class="py-3 first:pt-0 last:pb-0"
                    >
                        <a
                            href="{{ route('mod.show', [$dependent->id, $dependent->slug]) }}"
                            wire:navigate
                            class="group flex items-center gap-3"
                        >
                            @if ($dependent->thumbnail)
                                <img
                                    src="{{ $dependent->thumbnailUrl }}"
                                    @if ($dependent->thumbnailSrcset) srcset="{{ $dependent->thumbnailSrcset }}"
                                        sizes="3rem" @endif
                                    alt="{{ $dependent->name }}"
                                    width="192"
                                    height="192"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-12 w-12 flex-shrink-0 rounded-lg object-cover"
                                >
                            @else
                                <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg bg-gray-800">
                                    <flux:icon.cube-transparent class="h-6 w-6 text-gray-600" />
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-semibold text-gray-100 group-hover:text-cyan-400">
                                    {{ $dependent->name }}
                                </p>
                                <p class="text-xs text-gray-400">
                                    @if ($dependent->latestVersion)
                                        v{{ $dependent->latestVersion->version }}
                                    @endif
                                    @if ($dependent->owner)
                                        &middot;
                                        <x-user-name :user="$dependent->owner" />
                                    @endif
                                </p>
                            </div>

                            <div class="hidden shrink-0 items-center gap-3 sm:flex">
                                @if ($sptVersion = $dependent->latestVersion?->latestSptVersion)
                                    <span
                                        class="badge-version {{ $sptVersion->color_class }} inline-flex items-center text-nowrap rounded-md px-2 py-0.5 text-xs font-medium"
                                    >
                                        {{ $sptVersion->version_formatted }}
                                    </span>
                                @endif
                                <span
                                    class="inline-flex w-20 items-center justify-end gap-1 text-sm tabular-nums text-gray-400"
                                    title="{{ Number::format($dependent->downloads) }} {{ __(Str::plural('Download', $dependent->downloads)) }}"
                                >
                                    {{ Number::downloads($dependent->downloads) }}
                                    <flux:icon.arrow-down-tray class="size-4" />
                                </span>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($this->dependents->hasPages())
                <div class="mt-4 border-t border-gray-800 pt-4">
                    {{ $this->dependents->links() }}
                </div>
            @endif
        </div>
    @else
        <div class="rounded-xl bg-gray-950 p-4 shadow-md shadow-gray-950 drop-shadow-2xl sm:p-6">
            <div class="py-8 text-center">
                <flux:icon.cube-transparent class="mx-auto size-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-100">{{ __('No Dependents') }}</h3>
                <p class="mt-1 text-sm text-gray-400">{{ __('No published mod currently depends on this one.') }}</p>
            </div>
        </div>
    @endif
</div>
