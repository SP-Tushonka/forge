<div class="flex flex-col gap-6">
    {{-- Search --}}
    <flux:field>
        <flux:label>{{ __('Find a user') }}</flux:label>
        <flux:description>{{ __('Search by name, email address, user ID or Discord ID.') }}</flux:description>
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search…') }}"
        />
    </flux:field>

    @if ($this->results->isNotEmpty())
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('User') }}</flux:table.column>
                <flux:table.column>{{ __('Email') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Select') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->results as $result)
                    <flux:table.row :key="'result-' . $result->id">
                        <flux:table.cell>
                            {{ $result->name }}
                            <span class="text-zinc-400">#{{ $result->id }}</span>
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $result->email }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button
                                size="sm"
                                wire:click="selectUser({{ $result->id }})"
                            >{{ __('Select') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    {{-- Summary card --}}
    @if ($this->target !== null)
        @php($target = $this->target)
        <flux:card class="flex flex-col gap-3">
            <div class="flex items-start justify-between">
                <div>
                    <flux:heading size="lg">
                        {{ $target->name }}
                        <span class="text-zinc-400">#{{ $target->id }}</span>
                    </flux:heading>
                    <flux:subheading>
                        {{ $target->role?->name ?? __('No role') }} ·
                        {{ __('Joined') }} {{ $target->created_at?->format('M j, Y') }}
                    </flux:subheading>
                </div>
                <flux:button
                    size="sm"
                    variant="ghost"
                    wire:click="clearUser"
                >{{ __('Clear') }}</flux:button>
            </div>

            <flux:separator />

            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-zinc-400">{{ __('Email') }}</dt>
                    <dd class="font-mono text-xs">
                        {{ $target->email }}
                        @if (\App\Support\UndeliverableAddress::check($target->email))
                            <flux:badge
                                size="sm"
                                color="red"
                            >{{ __('Undeliverable') }}</flux:badge>
                        @elseif ($target->email_verified_at === null)
                            <flux:badge
                                size="sm"
                                color="amber"
                            >{{ __('Unverified') }}</flux:badge>
                        @else
                            <flux:badge
                                size="sm"
                                color="green"
                            >{{ __('Verified') }}</flux:badge>
                        @endif
                    </dd>
                </div>

                <div>
                    <dt class="text-zinc-400">{{ __('Password') }}</dt>
                    <dd>{{ $target->password === null ? __('Not set') : __('Set') }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-400">{{ __('Forge two-factor (TOTP)') }}</dt>
                    <dd>{{ $target->two_factor_confirmed_at !== null ? __('Enabled') : __('Not enabled') }}</dd>
                </div>

                <div>
                    <dt class="text-zinc-400">{{ __('Linked accounts') }}</dt>
                    <dd>
                        @forelse ($target->oAuthConnections as $connection)
                            <flux:badge size="sm">{{ $connection->provider }}</flux:badge>
                        @empty
                            {{ __('None') }}
                        @endforelse
                    </dd>
                </div>

                <div>
                    <dt class="text-zinc-400">{{ __('Images') }}</dt>
                    <dd>
                        {{ $target->profile_photo_path !== null ? __('Avatar set') : __('No avatar') }},
                        {{ $target->cover_photo_path !== null ? __('cover set') : __('no cover') }}
                    </dd>
                </div>

                <div>
                    <dt class="text-zinc-400">{{ __('Status') }}</dt>
                    <dd>
                        @if ($target->isBanned())
                            <flux:badge
                                size="sm"
                                color="red"
                            >{{ __('Banned') }}</flux:badge>
                        @else
                            <flux:badge
                                size="sm"
                                color="green"
                            >{{ __('Active') }}</flux:badge>
                        @endif
                    </dd>
                </div>
            </dl>

            <flux:separator />

            <div class="flex flex-wrap gap-2">
                <flux:button
                    size="sm"
                    wire:click="confirm('changeEmail')"
                >{{ __('Change email') }}</flux:button>
                <flux:button
                    size="sm"
                    variant="danger"
                    wire:click="confirm('removeEmail')"
                >{{ __('Remove email') }}</flux:button>
                <flux:button
                    size="sm"
                    wire:click="confirm('sendPasswordReset')"
                >{{ __('Send reset link') }}</flux:button>
                <flux:button
                    size="sm"
                    variant="danger"
                    wire:click="confirm('invalidatePassword')"
                >{{ __('Invalidate password') }}</flux:button>
                <flux:button
                    size="sm"
                    variant="danger"
                    wire:click="confirm('removeTwoFactor')"
                >{{ __('Remove two-factor') }}</flux:button>
                <flux:button
                    size="sm"
                    wire:click="confirm('unlinkDiscord')"
                >{{ __('Unlink Discord') }}</flux:button>
                <flux:button
                    size="sm"
                    variant="danger"
                    wire:click="confirm('lockAccount')"
                >{{ __('Lock account') }}</flux:button>
                <flux:button
                    size="sm"
                    wire:click="confirm('removeAvatar')"
                >{{ __('Remove avatar') }}</flux:button>
                <flux:button
                    size="sm"
                    wire:click="confirm('removeCover')"
                >{{ __('Remove cover') }}</flux:button>
            </div>
        </flux:card>
    @endif

    <flux:modal
        wire:model.self="showActionModal"
        class="md:w-[500px]"
    >
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Confirm staff action') }}</flux:heading>

            @error('action')
                <flux:callout variant="danger">{{ $message }}</flux:callout>
            @enderror

            @if ($pendingAction === 'unlinkDiscord')
                <flux:callout variant="warning">
                    {{ __('This does not lock anyone out. The user can re-link by signing in with Discord again, because the login flow re-attaches on a matching email address. To lock a compromised account, use Lock Account.') }}
                </flux:callout>
            @endif

            @if ($pendingAction === 'lockAccount')
                <flux:callout variant="danger">
                    {{ __('This removes every linked account, detaches the email address, and clears the password. It is for incident response and will lock the user out.') }}
                </flux:callout>
            @endif

            @if ($pendingAction === 'changeEmail')
                <flux:input
                    wire:model="newEmail"
                    type="email"
                    label="{{ __('New email address') }}"
                />
            @endif

            @if (in_array($pendingAction, ['removeEmail', 'lockAccount'], true))
                <flux:radio.group
                    wire:model="recoverable"
                    label="{{ __('Can the user recover this account?') }}"
                >
                    <flux:radio
                        value="1"
                        label="{{ __('Recoverable — the owner can prove ownership at /account/recover') }}"
                    />
                    <flux:radio
                        value="0"
                        label="{{ __('Permanent — the address is unrecoverable and staff must set a new one by hand') }}"
                    />
                </flux:radio.group>
            @endif

            <flux:textarea
                wire:model="reason"
                label="{{ __('Reason (required)') }}"
                rows="3"
            />

            <div class="flex justify-end gap-2">
                <flux:button
                    variant="ghost"
                    wire:click="cancel"
                >{{ __('Cancel') }}</flux:button>
                <flux:button
                    variant="danger"
                    wire:click="runAction"
                >{{ __('Run action') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
