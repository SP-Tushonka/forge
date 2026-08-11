<div>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-6">
            <flux:heading size="lg">{{ __('Choose a new password') }}</flux:heading>
            <flux:text class="mt-2 text-sm text-gray-400">
                {{ __('You are one step from having your old Forge account back. Pick a password and we will sign you straight in.') }}
            </flux:text>
        </div>

        <form wire:submit="submit">
            <div class="space-y-4">
                <flux:field>
                    <flux:label for="password">{{ __('Password') }}</flux:label>
                    <flux:input
                        id="password"
                        type="password"
                        wire:model="password"
                        required
                        autofocus
                        autocomplete="new-password"
                        placeholder="{{ __('Enter a secure password') }}"
                        data-test="recovery-password"
                    />
                    <flux:error name="password" />
                </flux:field>

                <flux:field>
                    <flux:label for="password_confirmation">{{ __('Confirm Password') }}</flux:label>
                    <flux:input
                        id="password_confirmation"
                        type="password"
                        wire:model="password_confirmation"
                        required
                        autocomplete="new-password"
                        placeholder="{{ __('Re-enter your password') }}"
                        data-test="recovery-password-confirmation"
                    />
                    <flux:error name="password_confirmation" />
                </flux:field>
            </div>

            <div class="mt-6">
                <flux:button
                    type="submit"
                    variant="primary"
                    class="w-full"
                    data-test="recovery-redeem-submit"
                >
                    {{ __('Recover my account') }}
                </flux:button>
            </div>
        </form>

        <div class="mt-6 text-center">
            <a
                class="focus:outline-hidden rounded-md text-sm text-gray-500 underline hover:text-gray-300 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2"
                href="{{ route('account.recovery.request') }}"
                wire:navigate
            >
                {{ __('Need a new recovery link?') }}
            </a>
        </div>
    </x-authentication-card>
</div>
