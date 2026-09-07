<div>
    <x-slot name="header">
        <div class="flex w-full items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-200">
                    {{ __('Staff Tools') }}
                </h2>
            </div>
        </div>
    </x-slot>

    <div
        x-data="{ selectedTab: window.location.hash ? window.location.hash.substring(1) : 'users' }"
        x-init="$watch('selectedTab', (tab) => { window.location.hash = tab })"
        class="flex flex-col gap-6 px-6 py-6 lg:px-8"
    >
        <div>
            {{-- Mobile --}}
            <div class="sm:hidden">
                <flux:select
                    variant="listbox"
                    x-model="selectedTab"
                    label:sr-only="{{ __('Select a tool') }}"
                >
                    <flux:select.option value="users">{{ __('Users') }}</flux:select.option>
                </flux:select>
            </div>

            {{-- Desktop --}}
            <div class="hidden sm:block">
                <nav
                    class="isolate flex divide-x divide-gray-800 rounded-xl shadow-md shadow-gray-950 drop-shadow-2xl"
                    aria-label="{{ __('Staff tools') }}"
                >
                    <x-tab-button
                        name="Users"
                        value="users"
                    />
                </nav>
            </div>
        </div>

        <div x-show="selectedTab === 'users'">
            <livewire:admin.staff-tools.user-tool />
        </div>
    </div>
</div>
