<x-slot:title>
    {{ __('Your Dashboard - The Forge') }}
</x-slot>

<x-slot:description>
    {{ __('The dashboard for your account on the Forge.') }}
</x-slot>

<x-slot:header>
    <h2 class="text-xl font-semibold leading-tight text-gray-100">
        {{ __('Dashboard') }}
    </h2>
</x-slot>

<div>
    <livewire:timezone-warning />

    <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
        @session('status')
            <flux:callout
                icon="check-circle"
                color="green"
            >
                <flux:callout.text>{{ $value }}</flux:callout.text>
            </flux:callout>
        @endsession

        @if ($this->report['mods'] === [])
            <div class="rounded-lg bg-gray-900 p-8 text-center shadow-xl">
                <flux:heading size="lg">{{ __('You have no mods yet') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Stats for your mods will appear here once you upload one.') }}
                </flux:text>
                <flux:button
                    href="{{ route('mod.create') }}"
                    variant="primary"
                    class="mt-4"
                >{{ __('Create a mod') }}</flux:button>
            </div>
        @else
            {{-- Not in the header slot: layout slots render outside the component root, where wire:model never binds. --}}
            <x-mod-stats.controls
                :range="$this->range"
                class="justify-end"
            />

            <x-mod-stats.summary :tiles="$this->report['summary']" />

            <x-mod-stats.traffic-chart
                :points="$this->report['traffic']"
                :since="$this->report['since']"
                :range="$this->range"
                :heading="__('Downloads & views, all your mods')"
            />

            <section class="rounded-lg bg-gray-900 p-4 shadow-xl">
                <flux:heading class="mb-3">
                    {{ trans_choice('{1} Your mod|[2,*] Your :count mods', count($this->report['mods']), ['count' => count($this->report['mods'])]) }}
                </flux:heading>
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column
                            sortable
                            :sorted="$sortBy === 'name'"
                            :direction="$sortDirection"
                            wire:click="sort('name')"
                        >{{ __('Mod') }}</flux:table.column>
                        <flux:table.column
                            align="end"
                            sortable
                            :sorted="$sortBy === 'downloads'"
                            :direction="$sortDirection"
                            wire:click="sort('downloads')"
                        >{{ __('Downloads') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Change') }}</flux:table.column>
                        <flux:table.column
                            align="end"
                            sortable
                            :sorted="$sortBy === 'views'"
                            :direction="$sortDirection"
                            wire:click="sort('views')"
                        >{{ __('Views') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Change') }}</flux:table.column>
                        <flux:table.column>{{ __('Trend') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->mods as $mod)
                            <flux:table.row :key="$mod['url']">
                                <flux:table.cell>
                                    <flux:link :href="$mod['url']">{{ $mod['name'] }}</flux:link>
                                    @if ($mod['status'])
                                        <flux:badge
                                            size="sm"
                                            color="amber"
                                            class="ms-2"
                                        >{{ __($mod['status']) }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell
                                    align="end"
                                    class="tabular-nums"
                                >{{ Number::format($mod['downloads']) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <x-mod-stats.change :change="$mod['downloads_change']" />
                                </flux:table.cell>
                                <flux:table.cell
                                    align="end"
                                    class="tabular-nums"
                                >{{ Number::format($mod['views']) }}</flux:table.cell>
                                <flux:table.cell align="end">
                                    <x-mod-stats.change :change="$mod['views_change']" />
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:chart
                                        :value="$mod['trend']"
                                        class="h-8 w-28"
                                        :aria-label="__('Downloads trend for :mod', ['mod' => $mod['name']])"
                                    >
                                        <flux:chart.svg gutter="0">
                                            <flux:chart.line
                                                field="downloads"
                                                class="text-stats-downloads"
                                            />
                                        </flux:chart.svg>
                                    </flux:chart>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </section>

            <flux:text
                size="sm"
                class="pb-6 text-center"
            >{{ __('All times are UTC. Each mod links to its full stats page.') }}</flux:text>
        @endif
    </div>
</div>
