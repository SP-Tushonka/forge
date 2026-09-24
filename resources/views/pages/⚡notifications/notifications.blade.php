<x-slot:title>
    {{ __('Your Notifications - The Forge') }}
</x-slot>

<x-slot:description>
    {{ __('Notifications for your account on the Forge.') }}
</x-slot>

<x-slot:header>
    <h2 class="text-xl font-semibold leading-tight text-gray-100">
        {{ __('Notifications') }}
    </h2>
</x-slot>

<div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
    <div class="overflow-hidden bg-gray-900 shadow-xl sm:rounded-lg">
        <livewire:notification-center />
    </div>
</div>
