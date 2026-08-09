<div>
    @if ($canClaim)
        <flux:button
            wire:click="{{ $this->claim ? '$set(\'showModal\', true)' : 'initiate' }}"
            variant="primary"
            icon="hand-raised"
            size="sm"
            class="w-full"
            data-test="mod-claim-button"
        >
            {{ $this->claim ? __('Continue Claim') : __('Claim Mod') }}
        </flux:button>

        <flux:modal
            name="mod-claim-modal"
            wire:model.self="showModal"
            class="md:w-[500px] lg:w-[600px]"
        >
            <div class="space-y-0">
                <div class="mb-6 border-b border-gray-700 pb-6">
                    <div class="flex items-center gap-3">
                        <flux:icon
                            name="hand-raised"
                            class="h-8 w-8 text-cyan-500"
                        />
                        <div>
                            <flux:heading
                                size="xl"
                                class="text-gray-100"
                            >
                                {{ __('Claim This Mod') }}
                            </flux:heading>
                            <flux:text class="mt-1 text-sm text-gray-400">
                                {{ __('Prove you control the source repository') }}
                            </flux:text>
                        </div>
                    </div>
                </div>

                @if ($this->claim?->status === \App\Enums\ModClaimStatus::Verified)
                    <div class="rounded-lg border border-green-800 bg-green-900/20 p-4">
                        <div class="flex items-start gap-3">
                            <flux:icon
                                name="check-circle"
                                class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-500"
                            />
                            <flux:text class="text-sm text-green-200">
                                {{ __('This mod is yours. You can now edit it from your dashboard.') }}
                            </flux:text>
                        </div>
                    </div>
                @else
                    <div class="space-y-6">
                        <div class="rounded-lg border border-blue-800 bg-blue-900/20 p-4">
                            <div class="flex items-start gap-3">
                                <flux:icon
                                    name="information-circle"
                                    class="mt-0.5 h-5 w-5 flex-shrink-0 text-blue-500"
                                />
                                <div>
                                    <flux:text class="text-sm font-medium text-blue-200">
                                        {{ __('How to claim') }}
                                    </flux:text>
                                    <flux:text class="mt-1 text-sm text-blue-300">
                                        {{ __('Add a file named claim.txt to the root of your repository containing only the token below, commit it to the default branch, then verify. You can delete the file once your claim is approved.') }}
                                    </flux:text>
                                </div>
                            </div>
                        </div>

                        @if ($this->claim)
                            <flux:input
                                :value="$this->claim->token"
                                label="{{ __('Your claim token') }}"
                                data-test="mod-claim-token"
                                readonly
                                copyable
                                class="font-mono"
                            />
                        @endif

                        @unless ($this->hasAutomaticSource)
                            <div class="rounded-lg border border-amber-800 bg-amber-900/20 p-4">
                                <div class="flex items-start gap-3">
                                    <flux:icon
                                        name="exclamation-triangle"
                                        class="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-500"
                                    />
                                    <flux:text class="text-sm text-amber-200">
                                        {{ __('This mod has no source we can check automatically, so a moderator will need to review your claim by hand.') }}
                                    </flux:text>
                                </div>
                            </div>
                        @endunless

                        @if ($this->claim?->escalated_at)
                            <div class="rounded-lg border border-green-800 bg-green-900/20 p-4">
                                <div class="flex items-start gap-3">
                                    <flux:icon
                                        name="clock"
                                        class="mt-0.5 h-5 w-5 flex-shrink-0 text-green-500"
                                    />
                                    <flux:text class="text-sm text-green-200">
                                        {{ __('A moderator is reviewing your claim, typically within 24-48 hours.') }}
                                    </flux:text>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            @if ($this->claim && $this->claim->status !== \App\Enums\ModClaimStatus::Verified)
                <div class="mt-6 flex flex-wrap gap-3">
                    @if ($this->hasAutomaticSource)
                        <flux:button
                            wire:click="verify"
                            wire:loading.attr="disabled"
                            wire:target="verify"
                            variant="primary"
                            icon="arrow-path"
                            data-test="mod-claim-verify"
                        >
                            <span wire:loading.remove wire:target="verify">{{ __('Verify') }}</span>
                            <span wire:loading wire:target="verify">{{ __('Checking...') }}</span>
                        </flux:button>
                    @endif

                    @unless ($this->claim->escalated_at)
                        <flux:button
                            wire:click="escalate"
                            wire:loading.attr="disabled"
                            wire:target="escalate"
                            variant="outline"
                            icon="flag"
                            data-test="mod-claim-escalate"
                        >
                            {{ __('Request manual review') }}
                        </flux:button>
                    @endunless
                </div>
            @endif
        </flux:modal>
    @endif
</div>
