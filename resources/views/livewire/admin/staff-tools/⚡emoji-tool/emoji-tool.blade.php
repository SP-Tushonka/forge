<div class="flex flex-col gap-6">
    {{-- Current whitelist --}}
    <div>
        <flux:heading size="lg">{{ __('Reaction emoji') }}</flux:heading>
        <flux:text class="mb-4 mt-1">
            {{ __('The shortcode is also what comments accept in markdown, so :gold: renders this emoji once assigned. Switching an emoji off stops it everywhere - reactions and markdown - but keeps every reaction already left with it, so switching it back on restores the counts exactly. Deleting is the irreversible alternative: it destroys those reactions too. The three columns restrict where an emoji may be used: unchecked, it is not offered there, its reactions stop counting towards that total, and in comments its shortcode is shown as plain text.') }}
        </flux:text>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Emoji') }}</flux:table.column>
                <flux:table.column>{{ __('Shortcode') }}</flux:table.column>
                <flux:table.column>{{ __('Label') }}</flux:table.column>
                <flux:table.column>{{ __('Order') }}</flux:table.column>
                @foreach (App\Enums\EmojiSurface::ordered() as $surface)
                    <flux:table.column align="center">{{ __($surface->label()) }}</flux:table.column>
                @endforeach
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->whitelist as $emoji)
                    <flux:table.row
                        :key="'emoji-' . $emoji->id"
                        data-test="emoji-row-{{ $emoji->id }}"
                    >
                        <flux:table.cell>
                            <div class="flex items-center gap-2">
                                <img
                                    src="{{ $emoji->image_url }}"
                                    alt="{{ $emoji->alt_text }}"
                                    class="size-6"
                                />
                                @if ($emoji->isCustom())
                                    <flux:badge
                                        size="sm"
                                        color="purple"
                                        data-test="emoji-custom-badge-{{ $emoji->shortcode }}"
                                    >{{ __('Custom') }}</flux:badge>
                                @endif
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <div class="flex items-center gap-1 font-mono text-xs text-gray-400">
                                <span>:</span>
                                <flux:input
                                    size="sm"
                                    class="max-w-44 font-mono"
                                    value="{{ $emoji->shortcode }}"
                                    x-ref="shortcode{{ $emoji->id }}"
                                    data-test="emoji-shortcode-{{ $emoji->id }}"
                                    label:sr-only="{{ __('Shortcode') }}"
                                />
                                <span>:</span>
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:input
                                size="sm"
                                class="max-w-48"
                                value="{{ $emoji->label }}"
                                x-ref="label{{ $emoji->id }}"
                                data-test="emoji-label-{{ $emoji->id }}"
                                label:sr-only="{{ __('Label') }}"
                            />
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:input
                                size="sm"
                                type="number"
                                class="max-w-20"
                                value="{{ $emoji->sort_order }}"
                                x-ref="order{{ $emoji->id }}"
                                label:sr-only="{{ __('Sort order') }}"
                            />
                        </flux:table.cell>

                        @foreach (App\Enums\EmojiSurface::ordered() as $surface)
                            <flux:table.cell align="center">
                                {{-- Saves on change, matching the Enabled button rather than waiting for Save. --}}
                                <flux:checkbox
                                    :checked="$emoji->allowsOn($surface)"
                                    wire:click="toggleSurface({{ $emoji->id }}, '{{ $surface->value }}')"
                                    data-test="emoji-surface-{{ $emoji->shortcode }}-{{ $surface->value }}"
                                    :label:sr-only="__($surface->label())"
                                />
                            </flux:table.cell>
                        @endforeach

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-2">
                                {{-- Save writes the whole row: shortcode, label and order. --}}
                                <flux:button
                                    size="sm"
                                    variant="filled"
                                    x-on:click="$wire.updateEmoji({{ $emoji->id }}, $refs.shortcode{{ $emoji->id }}.value, $refs.label{{ $emoji->id }}.value, Number($refs.order{{ $emoji->id }}.value))"
                                    data-test="emoji-save-{{ $emoji->shortcode }}"
                                >
                                    {{ __('Save') }}
                                </flux:button>

                                <flux:button
                                    size="sm"
                                    variant="{{ $emoji->enabled ? 'primary' : 'ghost' }}"
                                    wire:click="toggleEnabled({{ $emoji->id }})"
                                    data-test="emoji-toggle-{{ $emoji->shortcode }}"
                                >
                                    {{ $emoji->enabled ? __('Enabled') : __('Disabled') }}
                                </flux:button>

                                {{-- Deleting destroys the reactions too, so the modal spells that out first. --}}
                                <flux:button
                                    size="sm"
                                    variant="danger"
                                    icon="trash"
                                    :disabled="$this->whitelist->count() <= 1"
                                    wire:click="confirmDelete({{ $emoji->id }})"
                                    data-test="emoji-delete-{{ $emoji->shortcode }}"
                                >
                                    {{ __('Delete') }}
                                </flux:button>
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:separator />

    {{-- Add from the known shortcodes --}}
    <div>
        <flux:heading size="lg">{{ __('Add an emoji') }}</flux:heading>
        <flux:text class="mb-4 mt-1">
            {{ __('Pick from the standard emoji shortcodes. You can rename it to anything you like afterwards. An emoji with no Twemoji artwork cannot be added.') }}
        </flux:text>

        {{-- The catalogue is rendered once and filtered in the browser: a server-side filter meant a round trip per
             keystroke, which is why this list used to be capped at 120. Artwork is fetched by x-intersect because
             loading="lazy" applies its viewport-sized margin inside scrollers too, so it fetched and decoded all
             ~1,500 SVGs on every visit, stalling the page for seconds. wire:ignore keeps morphs from re-cloning the
             whole x-for on each request and from stripping the src attributes that x-intersect set. --}}
        <div
            wire:ignore
            x-data="{
                term: '',
                hovered: '',
                all: @js($this->candidates),
                base: '{{ asset('vendor/twemoji/svg') }}',
                get filtered() {
                    const term = this.term.trim().toLowerCase();
            
                    return term === '' ? this.all : this.all.filter((item) => item.shortcode.includes(term));
                },
            }"
        >
            <div class="mb-4 flex items-center gap-3">
                <flux:input
                    x-model="term"
                    placeholder="{{ __('Search shortcodes, e.g. rocket') }}"
                    class="max-w-sm"
                    label:sr-only="{{ __('Search emoji') }}"
                    data-test="emoji-search"
                />
                <flux:text class="text-xs">
                    <span x-show="hovered === ''">
                        <span x-text="filtered.length"></span>
                        {{ __('available') }}
                    </span>
                    <span
                        x-show="hovered !== ''"
                        x-cloak
                        class="font-mono text-cyan-300"
                        x-text="':' + hovered + ':'"
                    ></span>
                </flux:text>
            </div>

            <div
                class="max-h-96 overflow-y-auto rounded-lg border border-gray-800 p-2"
                data-test="emoji-catalogue"
            >
                <div class="flex flex-wrap gap-1">
                    {{-- One shared hover readout rather than a tooltip component per button: at this many items a
                         tooltip each is both heavy to render and enough extra wrapper markup to disturb the grid. --}}
                    <template
                        x-for="item in filtered"
                        :key="item.shortcode"
                    >
                        <button
                            type="button"
                            x-on:click="$wire.addEmoji(item.shortcode)"
                            x-on:mouseenter="hovered = item.shortcode"
                            x-on:mouseleave="hovered = ''"
                            class="flex size-8 shrink-0 items-center justify-center rounded transition hover:bg-gray-700"
                            :data-test="'emoji-add-' + item.shortcode"
                            :aria-label="':' + item.shortcode + ':'"
                        >
                            <img
                                x-intersect.once="$el.src = base + '/' + item.codepoints + '.svg'"
                                :alt="':' + item.shortcode + ':'"
                                class="size-6"
                            />
                        </button>
                    </template>
                </div>

                <p
                    x-show="filtered.length === 0"
                    class="px-1 py-3 text-sm text-gray-400"
                >{{ __('No matching shortcodes.') }}</p>
            </div>
        </div>
    </div>

    <flux:separator />

    {{-- Artwork of your own --}}
    <div>
        <flux:heading size="lg">{{ __('Upload a custom emoji') }}</flux:heading>
        <flux:text class="mb-4 mt-1">
            {{ __('PNG, WebP or GIF, up to 512KB. Animation is kept. The image is cropped square and stored at 128px. SVG is not accepted, because it can carry scripts.') }}
        </flux:text>

        {{-- Livewire uploads the file in its own request the moment one is chosen. Pressing Upload before that
             finishes submits an empty property, and the validator then reports the artwork as missing - which reads
             like a bug rather than a race. These events are Livewire's own, and they bubble, so one listener here
             covers the input regardless of the wire:ignore wrapper Flux puts around it. --}}
        <div
            class="flex flex-wrap items-end gap-3"
            x-data="{ uploading: false }"
            x-on:livewire-upload-start="uploading = true"
            x-on:livewire-upload-finish="uploading = false"
            x-on:livewire-upload-cancel="uploading = false"
            x-on:livewire-upload-error="uploading = false"
        >
            <flux:input
                wire:model="uploadShortcode"
                :label="__('Shortcode')"
                class="max-w-44 font-mono"
                data-test="custom-emoji-shortcode"
            />
            <flux:input
                wire:model="uploadLabel"
                :label="__('Label')"
                class="max-w-48"
                data-test="custom-emoji-label"
            />

            {{-- flux:input type="file" does not consume a label prop the way the text input does; it would be
                 forwarded onto the <input> as a stray attribute, so the label is declared explicitly. --}}
            <flux:field class="max-w-64">
                <flux:label>{{ __('Artwork') }}</flux:label>
                <flux:input
                    type="file"
                    wire:model="upload"
                    accept="image/png,image/webp,image/gif"
                    data-test="custom-emoji-file"
                />
            </flux:field>

            <flux:button
                variant="primary"
                x-bind:disabled="uploading"
                wire:click="addCustomEmoji"
                data-test="custom-emoji-submit"
            >
                <span x-show="! uploading">{{ __('Upload') }}</span>
                <span
                    x-show="uploading"
                    x-cloak
                >{{ __('Uploading...') }}</span>
            </flux:button>
        </div>

        <flux:error name="upload" />
    </div>

    <flux:modal
        wire:model.self="showDeleteModal"
        class="md:w-[520px]"
    >
        @if ($this->pendingDeletion !== null)
            @php($pending = $this->pendingDeletion)
            @php($shortcode = ':' . $pending['emoji']->shortcode . ':')

            <div
                class="flex flex-col gap-4"
                x-data="{ confirmation: '' }"
                x-effect="if (! $wire.showDeleteModal) confirmation = ''"
            >
                <flux:heading size="lg">{{ __('Delete this emoji?') }}</flux:heading>

                <div class="flex items-center gap-3">
                    <img
                        src="{{ $pending['emoji']->image_url }}"
                        alt="{{ $pending['emoji']->alt_text }}"
                        class="size-10"
                    />
                    <div>
                        <div class="font-mono text-sm text-gray-300">{{ $shortcode }}</div>
                        <flux:text class="text-xs">{{ $pending['emoji']->label }}</flux:text>
                    </div>
                </div>

                <flux:callout variant="danger">
                    <ul class="list-disc space-y-1 pl-4 text-sm">
                        <li>
                            @if ($pending['reactions'] === 0)
                                {{ __('Nobody has reacted with it, so no reaction is lost.') }}
                            @elseif ($pending['reactions'] === 1)
                                {{ __('The one reaction left with it is deleted for good.') }}
                            @else
                                {{ __(':count reactions left with it are deleted for good, and the totals on every mod and comment drop to match.', ['count' => number_format($pending['reactions'])]) }}
                            @endif
                        </li>
                        <li>
                            @if ($pending['fallback'] === null)
                                {{ __('Comments written with :shortcode will show that plain text instead of the emoji, because it is not a standard shortcode.', ['shortcode' => $shortcode]) }}
                            @elseif ($pending['fallbackMatches'])
                                {{ __('Comments written with :shortcode carry on working: it is a standard shortcode, so markdown still renders :glyph without us.', ['shortcode' => $shortcode, 'glyph' => $pending['fallback']]) }}
                            @else
                                {{ __('Comments written with :shortcode will render :glyph instead, because that is the standard emoji for this shortcode.', ['shortcode' => $shortcode, 'glyph' => $pending['fallback']]) }}
                            @endif
                        </li>
                        @if ($pending['emoji']->isCustom())
                            <li>{{ __('The uploaded artwork is deleted from storage. Unlike a standard emoji it cannot be restored by picking it from the catalogue again - it would have to be re-uploaded.') }}</li>
                        @endif
                        <li>{{ __('It disappears from every reaction picker at once.') }}</li>
                        <li>{{ __('None of this can be undone. Switching it to Disabled instead keeps the reactions and is reversible.') }}</li>
                    </ul>
                </flux:callout>

                <flux:input
                    x-model="confirmation"
                    autocomplete="off"
                    :label="__('Type delete to confirm')"
                    data-test="emoji-delete-confirmation"
                />

                <div class="flex justify-end gap-2">
                    <flux:button
                        variant="ghost"
                        wire:click="cancelDelete"
                        data-test="emoji-delete-cancel"
                    >{{ __('Cancel') }}</flux:button>
                    <flux:button
                        variant="danger"
                        x-bind:disabled="confirmation.trim().toLowerCase() !== 'delete'"
                        x-on:click="$wire.deleteEmoji(confirmation)"
                        data-test="emoji-delete-confirm"
                    >{{ __('Delete emoji') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
