<div>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6">
            <flux:heading size="lg">{{ __('Recover your account') }}</flux:heading>
            <flux:text class="mt-2 text-sm text-gray-400">
                {{ __('The Forge was rebuilt, and every account brought over from the old site had its email address removed. If you had an account there, enter the address you used and we will send you a link to take it back, along with your mods, comments and history.') }}
            </flux:text>
        </div>

        <form wire:submit="submit">
            <flux:field>
                <flux:label for="email">{{ __('Email') }}</flux:label>
                <flux:input
                    id="email"
                    type="email"
                    wire:model="email"
                    required
                    autofocus
                    autocomplete="username"
                    placeholder="your@email.com"
                    data-test="recovery-email"
                />
                <flux:error name="email" />
            </flux:field>

            <div class="mt-6">
                <flux:button
                    type="submit"
                    variant="primary"
                    class="w-full"
                    data-test="recovery-submit"
                >
                    {{ __('Send recovery link') }}
                </flux:button>
            </div>
        </form>

        <div class="mt-6 text-center">
            <a
                class="focus:outline-hidden rounded-md text-sm text-gray-500 underline hover:text-gray-300 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                href="{{ route('login') }}"
                wire:navigate
            >
                {{ __('Back to sign in') }}
            </a>
        </div>
    </x-authentication-card>
</div>
