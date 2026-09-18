import { Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

/**
 * Shortcode autocomplete for the markdown editor.
 *
 * The single regex below encodes every rule about when a colon is an emoji and when it is just punctuation:
 *
 *   (?:^|\s)   the colon must start the field or follow whitespace, so "check this out?:" never triggers,
 *              nor does "http://x", "10:30" or "note: hi". This is the same rule the markdown parser uses.
 *   ([...]+)   at least one shortcode character must follow, so a lone ":" sitting after a space does nothing.
 *   $          the match must end at the caret, so typing a space or the closing colon dismisses it.
 */
const TRIGGER = /(?:^|\s):([a-z0-9_+-]+)$/i;

const MAX_MATCHES = 8;

Alpine.data('emojiAutocomplete', (choices = []) => ({
    choices,
    open: false,
    query: '',
    activeIndex: 0,

    /** Index of the ':' that opened the menu, so insertion knows what to replace. */
    triggerAt: null,

    get matches() {
        if (this.query === '') {
            return [];
        }

        return this.choices
            .filter(
                (choice) => choice.shortcode.startsWith(this.query) || choice.label.toLowerCase().includes(this.query),
            )
            .slice(0, MAX_MATCHES);
    },

    input() {
        const el = this.$refs.editorInput;

        if (!el) {
            return;
        }

        const value = el.value ?? '';
        const caret = el.selectionStart ?? value.length;
        const found = value.slice(0, caret).match(TRIGGER);

        if (!found) {
            this.close();

            return;
        }

        this.query = found[1].toLowerCase();
        this.triggerAt = caret - found[1].length - 1;
        this.activeIndex = 0;
        this.open = this.matches.length > 0;
    },

    keydown(event) {
        // Every key is left alone while the menu is shut, so Enter still makes a newline and Tab still moves focus.
        if (!this.open) {
            return;
        }

        const total = this.matches.length;

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.activeIndex = (this.activeIndex + 1) % total;
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.activeIndex = (this.activeIndex - 1 + total) % total;
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            this.choose(this.activeIndex);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
        }
    },

    choose(index) {
        const choice = this.matches[index];
        const el = this.$refs.editorInput;

        if (!choice || !el || this.triggerAt === null) {
            return;
        }

        const value = el.value ?? '';
        const caret = el.selectionStart ?? value.length;
        const insertion = `:${choice.shortcode}: `;
        const before = value.slice(0, this.triggerAt);

        el.value = before + insertion + value.slice(caret);

        const position = before.length + insertion.length;
        el.setSelectionRange(position, position);

        // wire:model listens for input events; setting .value alone would leave Livewire holding the old text.
        el.dispatchEvent(new Event('input', { bubbles: true }));

        this.close();
        el.focus();
    },

    close() {
        this.open = false;
        this.query = '';
        this.triggerAt = null;
        this.activeIndex = 0;
    },
}));
