@props([
    'wireModel',
    'name',
    'label' => null,
    'description' => null,
    'placeholder' => '',
    'rows' => 6,
    'purifyConfig' => 'description',
    'errorName' => null,
    'showUpdateRequestWarning' => false,
])

@php
    // Only what a comment will actually render. Suggesting a shortcode that would come out as literal text would be
    // worse than not suggesting it at all.
    $emojiChoices = resolve(App\Services\ReactionSummaryService::class)
        ->whitelistFor(App\Enums\EmojiSurface::Comments)
        ->map(
            fn(App\Models\Emoji $emoji): array => [
                'shortcode' => $emoji->shortcode,
                'label' => $emoji->label,
                'url' => $emoji->image_url,
            ],
        )
        ->values()
        ->all();

    // Whatever the caller passes (maxlength, data-test) belongs on the textarea the user types into.
    $textareaProps = ['name' => $name, ...$attributes->getAttributes()];
@endphp

<div
    x-data="{
        activeTab: 'write',
        previewHtml: '',
        isLoadingPreview: false,
        unwatch: null,
        containsLogFile: false,
        logFilePattern: null,
        containsUpdateRequest: false,
        updateRequestPattern: null,
        init() {
            this.logFilePattern = new RegExp('(?:\\[(?:Message|Info|Warning|Error)\\s*:\\s+[^\\]]+\\]|\\[\\d{4}-\\d{2}-\\d{2}\\s+\\d{2}:\\d{2}:\\d{2}\\.\\d{3}\\]\\[(?:Info|Debug|Warning|Error)\\]\\[|\\d{4}-\\d{2}-\\d{2}\\s+\\d{2}:\\d{2}:\\d{2}\\.\\d{3}\\s+[+\\-]\\d{2}:\\d{2}\\|\\d+\\.\\d+\\.\\d+\\.\\d+\\.\\d+\\||&quot;_(?:id|tpl)&quot;:\\s*&quot;[0-9a-f]{24}&quot;)');
            this.updateRequestPattern = new RegExp('(?:when\\s+(?:will|can|are|is)(?:\\s+(?:this|the))?(?:\\s+mod)?(?:\\s+be)?|can\\s+(?:you|u|it)|please|pls|plz|any\\s+(?:plans|eta|chance)(?:\\s+to)?|will\\s+there\\s+be|(?:is\\s+this\\s+)?gonna\\s+be|does\\s+(?:this\\s+)?(?:mod\\s+)?(?:work|support))\\s+(?:you\\s+)?(?:update(?:d)?|port(?:ed)?|support(?:ed)?|make\\s+(?:it\\s+)?(?:work|compatible)|new\\s+versions?)(?:\\s+(?:this|it|the\\s+mod|to|for|with))?|(?:update|port|support)(?:d)?\\s+(?:this|it|the\\s+mod|for|to)(?:\\s+(?:ver(?:sion)?|spt|latest|new|newer|\\d+\\.\\d+(?:\\.\\d+)?(?:\\.\\w+)?))?|(?:work|working|compatible)(?:ing)?\\s+(?:with|on|for)(?:\\s+(?:older\\s+)?(?:ver(?:sion)?(?:\\s+of)?|spt|latest|new|newer|\\d+\\.\\d+(?:\\.\\d+)?(?:\\.\\w+)?))?|waiting\\s+for\\s+(?:update|port)|(?:still|not)\\s+(?:working|updated|supported)', 'i');
            // The editor writes straight to Livewire, so the warnings follow the property itself.
            this.unwatch = $wire.$watch('{{ $wireModel }}', (content) => {
                this.checkForLogFile(content);
                this.checkForUpdateRequest(content);
            });
        },
        destroy() {
            this.unwatch?.();
        },
        checkForLogFile(content) {
            const hasLogFile = this.logFilePattern.test(content || '');
            if (this.containsLogFile !== hasLogFile) {
                this.containsLogFile = hasLogFile;
                this.$dispatch('log-file-detected', { containsLogFile: hasLogFile });
            }
        },
        checkForUpdateRequest(content) {
            const hasUpdateRequest = this.updateRequestPattern.test(content || '');
            if (this.containsUpdateRequest !== hasUpdateRequest) {
                this.containsUpdateRequest = hasUpdateRequest;
                this.$dispatch('update-request-detected', { containsUpdateRequest: hasUpdateRequest });
            }
        },
        async switchToPreview() {
            this.activeTab = 'preview';
            this.isLoadingPreview = true;
            try {
                this.previewHtml = await $wire.previewMarkdown($wire.$get('{{ $wireModel }}') ?? '', '{{ $purifyConfig }}');
                // Wait for DOM to update, then initialize tabs
                await this.$nextTick();
                // Dispatch event to initialize tabsets
                this.$dispatch('content-updated');
            } catch (error) {
                console.error('Preview error:', error);
                this.previewHtml = '<p class=\'text-red-500\'>' + '{{ __('Error generating preview.') }}' + '</p>';
            } finally {
                this.isLoadingPreview = false;
            }
        },
        switchToWrite() {
            this.activeTab = 'write';
        }
    }"
    class="space-y-2"
    wire:key="markdown-editor-{{ $name }}"
>
    @if ($label)
        <flux:label>{{ $label }}</flux:label>
    @endif

    @if ($description)
        <flux:description>{{ $description }}</flux:description>
    @endif

    {{-- Tab Navigation --}}
    <div
        class="flex items-center gap-2 border-b border-slate-700"
        role="tablist"
    >
        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'write'"
            tabindex="0"
            @click="switchToWrite"
            :class="{
                'border-cyan-600 text-white': activeTab === 'write',
                'border-transparent text-slate-400 hover:text-slate-300': activeTab !== 'write'
            }"
            class="rounded-t-lg border-b-2 px-4 py-2 text-sm font-medium transition-colors focus:bg-slate-800 focus:outline-none"
        >
            {{ __('Write') }}
        </button>
        <button
            type="button"
            role="tab"
            :aria-selected="activeTab === 'preview'"
            tabindex="0"
            @click="switchToPreview"
            :class="{
                'border-cyan-600 text-white': activeTab === 'preview',
                'border-transparent text-slate-400 hover:text-slate-300': activeTab !== 'preview'
            }"
            class="rounded-t-lg border-b-2 px-4 py-2 text-sm font-medium transition-colors focus:bg-slate-800 focus:outline-none"
        >
            {{ __('Preview') }}
        </button>
    </div>

    {{-- Content Area --}}
    <div class="relative">
        {{-- Write Tab --}}
        <div
            x-show="activeTab === 'write'"
            x-cloak
            role="tabpanel"
            :aria-hidden="activeTab !== 'write'"
        >
            <div
                class="relative"
                x-data="markdownEditor({
                    model: @js($wireModel),
                    placeholder: @js($placeholder),
                    textareaProps: @js($textareaProps),
                    emoji: @js($emojiChoices),
                })"
                x-on:click.outside="close()"
            >
                {{-- The frame stays outside wire:ignore so its error border still follows validation. --}}
                <div @class([
                    'markdown-editor-frame',
                    'markdown-editor-frame-invalid' => $errors->has($errorName ?? $name),
                ])>
                    <div
                        wire:ignore
                        x-ref="host"
                    ></div>
                </div>

                {{-- Anchored under the field rather than at the caret: with a short curated list this reads clearly
                     and avoids measuring caret coordinates in a resizing textarea. --}}
                <div
                    x-show="open"
                    x-cloak
                    class="absolute left-0 top-full z-30 mt-1 w-max min-w-56 overflow-hidden rounded-lg border border-gray-700 bg-gray-900 py-1 shadow-lg shadow-gray-950"
                    data-test="emoji-autocomplete"
                >
                    <template
                        x-for="(choice, index) in matches"
                        :key="choice.shortcode"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm transition"
                            :class="index === activeIndex ? 'bg-cyan-950/60 text-cyan-200' : 'text-gray-300 hover:bg-gray-800'"
                            x-on:mousedown.prevent="choose(index)"
                            x-on:mouseenter="activeIndex = index"
                            :data-test="'emoji-autocomplete-option-' + choice.shortcode"
                        >
                            <img
                                :src="choice.url"
                                :alt="choice.label"
                                class="size-5"
                            />
                            <span
                                class="font-mono text-xs"
                                x-text="':' + choice.shortcode + ':'"
                            ></span>
                            <span
                                class="ml-auto text-xs text-gray-400"
                                x-text="choice.label"
                            ></span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        {{-- Preview Tab --}}
        <div
            x-show="activeTab === 'preview'"
            x-cloak
            role="tabpanel"
            :aria-hidden="activeTab !== 'preview'"
            class="min-h-[{{ $rows * 1.5 }}rem] rounded-xl border border-slate-700 bg-white/10 px-4 py-3 sm:px-6 sm:py-4"
        >
            <div
                x-show="isLoadingPreview"
                class="flex items-center justify-center py-8"
            >
                <svg
                    class="h-8 w-8 animate-spin text-cyan-500"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle
                        class="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        stroke-width="4"
                    ></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                </svg>
            </div>
            <div
                x-show="!isLoadingPreview"
                x-html="previewHtml"
                class="user-markdown"
            ></div>
        </div>
    </div>

    {{-- Log File Detection Warning --}}
    <div
        x-show="containsLogFile"
        x-cloak
    >
        <flux:callout
            variant="danger"
            icon="x-circle"
        >
            <flux:callout.heading>{{ __('Log files detected!') }}</flux:callout.heading>
            <flux:callout.text>
                Please use our code paste service instead:
                <flux:callout.link
                    href="https://codepaste.sp-mod.com"
                    external
                >https://codepaste.sp-mod.com</flux:callout.link>
            </flux:callout.text>
        </flux:callout>
    </div>

    {{-- Update Request Warning --}}
    @if ($showUpdateRequestWarning)
        <div
            x-show="containsUpdateRequest"
            x-cloak
        >
            <flux:callout
                variant="warning"
                icon="exclamation-triangle"
            >
                <flux:callout.heading>{{ __('Warning: Potential Update Request Detected') }}</flux:callout.heading>
                <flux:callout.text>
                    Pestering or harassing mod authors to update their mods is against our <flux:callout.link
                        href="/community-standards"
                        external
                    >community guidelines</flux:callout.link>. First offense is a 7-day ban. Please be respectful and
                    patient with mod authors.
                </flux:callout.text>
            </flux:callout>
        </div>
    @endif

    <flux:error name="{{ $errorName ?? $name }}" />
</div>
