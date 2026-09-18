// Bundled rather than loaded on demand, so the textarea exists as soon as the page does, as a plain textarea did.
// Mounting after load raced anything that reached for the field straight away.
import OverType, { toolbarButtons } from 'overtype';
import { Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import { emojiAutocomplete } from './emojiAutocomplete';

// Tailwind's font-mono stack. OverType needs a monospace font to keep its overlay aligned with the caret.
const FONT_MONO = "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace";

const WHITE_10 = 'rgb(255 255 255 / 0.1)';

// Tailwind palette values written out, because Tailwind only emits the colour variables a page uses. Keys left out
// fall back to OverType's dark "cave" theme.
const COLOURS = {
    bgPrimary: 'transparent',
    bgSecondary: 'transparent',
    toolbarBg: 'transparent',
    text: 'oklch(87.2% 0.01 258.338)', // gray-300
    code: 'oklch(87.2% 0.01 258.338)', // gray-300
    h1: '#fff',
    h2: '#fff',
    h3: '#fff',
    strong: '#fff',
    link: 'oklch(86.5% 0.127 207.078)', // cyan-300
    codeBg: 'oklch(37.3% 0.034 259.733)', // gray-700
    blockquote: 'oklch(70.7% 0.022 261.325)', // gray-400
    syntaxMarker: 'oklch(55.4% 0.046 257.417)', // slate-500
    placeholder: 'oklch(55.4% 0.046 257.417)', // slate-500
    listMarker: 'oklch(71.5% 0.143 215.221)', // cyan-500
    primary: 'oklch(71.5% 0.143 215.221)', // cyan-500
    cursor: 'oklch(78.9% 0.154 211.53)', // cyan-400
    selection: 'oklch(71.5% 0.143 215.221 / 0.3)', // cyan-500 at 30%
    border: WHITE_10,
    hoverBg: WHITE_10,
    toolbarHover: WHITE_10,
    toolbarActive: 'rgb(255 255 255 / 0.2)',
    toolbarIcon: 'oklch(70.7% 0.022 261.325)', // gray-400
};

const PRESETS = {
    full: {
        toolbar: true,
        smartLists: true,
        minHeight: '100px',
        maxHeight: null,
        emoji: true,
        sendOnEnter: false,
        autofocus: false,
    },
    chat: {
        toolbar: false,
        smartLists: false,
        minHeight: '46px',
        maxHeight: '120px',
        emoji: false,
        sendOnEnter: true,
        autofocus: true,
    },
};

function toolbar(buttons) {
    const { separator } = buttons;

    return [
        buttons.bold,
        buttons.italic,
        buttons.code,
        separator,
        buttons.link,
        separator,
        buttons.h1,
        buttons.h2,
        buttons.h3,
        separator,
        buttons.bulletList,
        buttons.orderedList,
        separator,
        buttons.quote,
    ];
}

OverType.setTheme('cave', COLOURS);

Alpine.data('markdownEditor', ({ model, preset = 'full', placeholder = '', textareaProps = {}, emoji = [] }) => {
    const settings = PRESETS[preset];

    // Kept out of Alpine's data: Alpine would wrap the instance, and every DOM node it holds, in a reactive proxy.
    let editor = null;
    let unwatch = null;

    return {
        ...(settings.emoji ? emojiAutocomplete(emoji) : {}),

        textarea() {
            return editor?.textarea ?? null;
        },

        init() {
            const stored = () => this.$wire.$get(model) ?? '';

            [editor] = new OverType(this.$refs.host, {
                value: stored(),
                placeholder,
                fontFamily: FONT_MONO,
                fontSize: '14px',
                padding: '12px',
                // iOS zooms the page into any field under 16px.
                mobile: { fontSize: '16px' },
                autoResize: true,
                minHeight: settings.minHeight,
                maxHeight: settings.maxHeight,
                toolbar: settings.toolbar,
                ...(settings.toolbar && { toolbarButtons: toolbar(toolbarButtons) }),
                smartLists: settings.smartLists,
                autofocus: settings.autofocus,
                spellcheck: true,
                textareaProps: Object.fromEntries(Object.entries(textareaProps).filter(([, value]) => value != null)),
                // Also called for the initial value and for every setValue(), so only a real difference is written back.
                onChange: (value) => {
                    if (value !== stored()) {
                        this.$wire.$set(model, value, false);
                    }
                },
            });

            unwatch = this.$wire.$watch(model, (value) => {
                // A change queued as the form closed can still arrive after destroy().
                if (editor && (value ?? '') !== editor.getValue()) {
                    editor.setValue(value ?? '');
                }
            });

            if (settings.emoji) {
                // On the textarea itself, so they run before OverType's document-level handlers.
                editor.textarea.addEventListener('input', () => this.input());
                editor.textarea.addEventListener('keydown', (event) => this.keydown(event));
                editor.textarea.addEventListener('blur', () => this.close());
            }

            if (settings.sendOnEnter) {
                editor.textarea.addEventListener('keydown', (event) => {
                    // An Enter that confirms an IME composition is not a send.
                    if (event.key !== 'Enter' || event.shiftKey || event.isComposing) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    this.$dispatch('markdown-editor-submit');
                });
            }
        },

        destroy() {
            // Livewire only drops $watch callbacks with the whole component, which outlives a reply form's editor.
            unwatch?.();
            editor?.destroy();
            editor = null;
        },
    };
});
