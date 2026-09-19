<div>
    <flux:modal
        name="mod-issue-ban"
        wire:model.self="showBanModal"
        class="md:w-[480px]"
    >
        @if ($this->target)
            <form
                wire:submit="ban"
                class="space-y-5"
            >
                <div>
                    <flux:heading size="lg">{{ __('Ban :name from issues', ['name' => $this->target->name]) }}
                    </flux:heading>
                    <flux:text class="mt-2">
                        {{ __('They will not be able to open or comment on issues for :mod. Their existing issues stay up.', ['mod' => $this->mod->name]) }}
                    </flux:text>
                </div>

                <flux:radio.group
                    wire:model="duration"
                    :label="__('Duration')"
                >
                    <flux:radio
                        value="7"
                        :label="__('7 days')"
                    />
                    <flux:radio
                        value="30"
                        :label="__('30 days')"
                    />
                    <flux:radio
                        value="permanent"
                        :label="__('Permanent')"
                    />
                </flux:radio.group>

                <flux:textarea
                    wire:model="reason"
                    :label="__('Private note')"
                    :description="__('Only the mod authors and staff can see this.')"
                    rows="3"
                />

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button
                        type="submit"
                        variant="danger"
                        data-test="issue-ban-confirm"
                    >{{ __('Ban') }}</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
