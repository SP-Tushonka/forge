<div>
    <x-slot name="header">
        <div class="flex w-full items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-200">
                    {{ __('License Management') }}
                </h2>
            </div>
        </div>
    </x-slot>

    <div class="px-6 lg:px-8">
        <div class="space-y-6">
            {{-- Actions Bar --}}
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-wrap gap-2">
                    <flux:button
                        wire:click="showCreateLicense"
                        variant="primary"
                        icon="plus"
                    >
                        Add License
                    </flux:button>
                </div>

                <p class="text-sm text-gray-400">
                    Licenses cannot be edited or removed.
                </p>
            </div>

            {{-- Table Section --}}
            <div class="overflow-hidden rounded-lg border border-gray-700 bg-gray-900 shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-700">
                        <thead class="bg-gray-800">
                            <tr>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400"
                                >
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400"
                                >
                                    Link
                                </th>
                                <th
                                    scope="col"
                                    class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-400"
                                >
                                    Mods
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-700 bg-gray-900">
                            @forelse($this->licenses as $license)
                                <tr class="hover:bg-gray-800">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-100">
                                        {{ $license->name }}
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-300">
                                        @if ($license->link !== '')
                                            <a
                                                href="{{ $license->link }}"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="inline-flex items-center gap-1 text-blue-400 hover:underline"
                                            >
                                                {{ $license->link }}
                                                <flux:icon.arrow-top-right-on-square variant="micro" />
                                            </a>
                                        @else
                                            <span class="text-gray-500">{{ __('No link') }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-300">
                                        {{ number_format($license->mods_count) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td
                                        colspan="4"
                                        class="px-6 py-12 text-center"
                                    >
                                        <div class="flex flex-col items-center justify-center gap-2">
                                            <flux:icon.inbox class="h-12 w-12 text-gray-600" />
                                            <p class="text-gray-400">No licenses found</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Create Modal --}}
    <flux:modal
        wire:model.self="showCreateModal"
        variant="flyout"
    >
        <form wire:submit.prevent="createLicense">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add License</flux:heading>
                    <flux:subheading>Licenses are permanent. Once added it cannot be edited or removed.
                    </flux:subheading>
                </div>

                <flux:separator />

                <div class="space-y-4">
                    {{-- Name Input --}}
                    <flux:field>
                        <flux:label for="formName">Name</flux:label>
                        <flux:input
                            type="text"
                            wire:model.defer="formName"
                            id="formName"
                            placeholder="MIT License"
                            required
                        />
                        <flux:error name="formName" />
                    </flux:field>

                    {{-- Link Input --}}
                    <flux:field>
                        <flux:label for="formLink">Link</flux:label>
                        <flux:input
                            type="url"
                            wire:model.defer="formLink"
                            id="formLink"
                            placeholder="https://opensource.org/license/mit"
                            required
                        />
                        <flux:error name="formLink" />
                        <flux:description>Must be an http or https address pointing at the full licence text.
                        </flux:description>
                    </flux:field>
                </div>

                <flux:separator />

                <div class="flex gap-2">
                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                    >
                        <span
                            wire:loading.remove
                            wire:target="createLicense"
                        >Create</span>
                        <span
                            wire:loading
                            wire:target="createLicense"
                        >Saving...</span>
                    </flux:button>
                    <flux:button
                        type="button"
                        variant="ghost"
                        wire:click="closeModal"
                    >Cancel</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>
</div>
