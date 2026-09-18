<?php

declare(strict_types=1);

use App\Enums\EmojiSurface;
use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\Emoji;
use App\Models\Reaction;
use App\Rules\ProcessableAnimation;
use App\Services\ReactionSummaryService;
use App\Services\ThumbnailService;
use ElGigi\CommonMarkEmoji\Emoji as EmojiCodes;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Curates the reaction emoji whitelist.
 *
 * Retiring an emoji normally means flipping `enabled`, which keeps every reaction row so switching it back on restores
 * the counts exactly. Deleting is the irreversible alternative: it destroys those reactions too, so it sits behind a
 * typed confirmation and refuses to remove the last emoji.
 */
new class extends Component
{
    use WithFileUploads;

    public bool $showDeleteModal = false;

    /** The pending artwork. Typed mixed to match how Livewire uploads are declared elsewhere in this codebase. */
    public mixed $upload = null;

    public string $uploadShortcode = '';

    public string $uploadLabel = '';

    public ?int $deletingId = null;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');
    }

    /**
     * The whitelist as staff see it: everything, enabled or not, in display order.
     *
     * @return Collection<int, Emoji>
     */
    #[Computed]
    public function whitelist(): Collection
    {
        return Emoji::query()->orderBy('sort_order')->orderBy('shortcode')->get();
    }

    /**
     * Every emoji that can still be added, in Unicode codepoint order.
     *
     * The whole catalogue is returned rather than a page of it: the search box filters client-side, so there is no
     * per-keystroke round trip to pay for and no reason to truncate. Codepoint order is used instead of alphabetical
     * shortcode order because Unicode allocates emoji in blocks - symbols, flags, pictographs, faces, transport - so
     * related emoji end up beside each other instead of being scattered by name.
     *
     * Anything without Twemoji artwork is left out: addEmoji() would refuse it, so offering it is a dead end.
     *
     * @return list<array{shortcode: string, codepoints: string}>
     */
    #[Computed]
    public function candidates(): array
    {
        $takenShortcodes = $this->whitelist->pluck('shortcode')->all();
        $takenCodepoints = $this->whitelist->pluck('codepoints')->all();
        $artwork = $this->artwork();

        $candidates = [];

        foreach ($this->knownEmoji() as $key => $glyph) {
            $shortcode = (string) $key;

            if (in_array($shortcode, $takenShortcodes, true)) {
                continue;
            }

            $codepoints = $this->twemojiCodepoints($glyph);

            if (in_array($codepoints, $takenCodepoints, true) || ! isset($artwork[$codepoints])) {
                continue;
            }

            $candidates[] = ['shortcode' => $shortcode, 'codepoints' => $codepoints];
        }

        usort($candidates, function (array $a, array $b): int {
            $first = fn (string $codepoints): int => (int) hexdec(explode('-', $codepoints)[0]);

            return [$first($a['codepoints']), $a['shortcode']] <=> [$first($b['codepoints']), $b['shortcode']];
        });

        return $candidates;
    }

    /**
     * The codepoints Twemoji actually ships, as a lookup set. Cached: the directory only changes on deploy, and
     * scanning 3,800 files on every render would be wasteful.
     *
     * @return array<string, true>
     */
    private function artwork(): array
    {
        /** @var array<string, true> */
        return Cache::remember('emoji.twemoji-artwork', 3600, function (): array {
            $index = [];

            foreach (scandir(public_path('vendor/twemoji/svg')) ?: [] as $file) {
                if (str_ends_with((string) $file, '.svg')) {
                    $index[substr((string) $file, 0, -4)] = true;
                }
            }

            return $index;
        });
    }

    /**
     * The vendor shortcode map, narrowed once. ElGigi's Emoji::$codes is declared as a bare array.
     *
     * Keys are array-key rather than string on purpose: PHP stores numeric string keys as integers, so shortcodes
     * like "100" and "1234" come back as ints no matter how they are written.
     *
     * @return array<array-key, string>
     */
    private function knownEmoji(): array
    {
        $known = [];

        foreach (EmojiCodes::$codes as $shortcode => $glyph) {
            if (is_string($glyph)) {
                $known[$shortcode] = $glyph;
            }
        }

        return $known;
    }

    /**
     * Retire or restore an emoji. Reactions that already use it are never touched.
     */
    public function toggleEnabled(int $emojiId): void
    {
        $emoji = Emoji::query()->findOrFail($emojiId);

        $emoji->update(['enabled' => ! $emoji->enabled]);

        $this->recordChange($emoji->enabled ? 'enabled' : 'disabled', $emoji);
    }

    /**
     * Allow or forbid an emoji on one surface. Reactions already left with it are untouched, exactly as with the
     * enabled toggle: switching a surface back on restores its counts there intact.
     */
    public function toggleSurface(int $emojiId, string $surface): void
    {
        $target = EmojiSurface::tryFrom($surface);

        if (! $target instanceof EmojiSurface) {
            return;
        }

        $emoji = Emoji::query()->findOrFail($emojiId);
        $column = $target->column();

        $emoji->update([$column => ! $emoji->allowsOn($target)]);

        $this->recordChange($emoji->allowsOn($target) ? 'allowed on '.$target->value : 'blocked on '.$target->value, $emoji);
    }

    /**
     * Stage a deletion. Nothing is touched until deleteEmoji() runs; this only tells the modal what to describe.
     */
    public function confirmDelete(int $emojiId): void
    {
        $this->deletingId = $emojiId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
    }

    /**
     * What the staged deletion would destroy.
     *
     * `fallback` is the glyph the vendor markdown extension would produce once ours stops claiming the shortcode, so
     * the modal can be specific rather than vague: null means staff invented the shortcode - :gold: is not standard,
     * so comments using it drop back to plain text - while a glyph means markdown carries on without us.
     *
     * `fallbackMatches` compares the two by codepoint rather than by string. The stored heart is U+2764 U+FE0F and
     * the vendor's is a bare U+2764: the same emoji, so saying markdown would render "a different one" would be a lie.
     *
     * @return array{emoji: Emoji, reactions: int, fallback: string|null, fallbackMatches: bool}|null
     */
    #[Computed]
    public function pendingDeletion(): ?array
    {
        $emoji = $this->deletingId === null ? null : Emoji::query()->find($this->deletingId);

        if (! $emoji instanceof Emoji) {
            return null;
        }

        $fallback = $this->knownEmoji()[$emoji->shortcode] ?? null;

        return [
            'emoji' => $emoji,
            'reactions' => Reaction::query()->where('emoji_id', $emoji->id)->count(),
            'fallback' => $fallback,
            'fallbackMatches' => $fallback !== null && $this->twemojiCodepoints($fallback) === $emoji->codepoints,
        ];
    }

    /**
     * Delete an emoji along with every reaction left with it.
     *
     * reactions.emoji_id stays restrictOnDelete and the rows are cleared explicitly inside a transaction, rather than
     * the constraint being relaxed to a cascade: no other path can then orphan a reaction by accident, and this one
     * knows the count it destroyed for the audit log.
     */
    public function deleteEmoji(string $confirmation): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');

        $pending = $this->pendingDeletion;

        if ($pending === null) {
            $this->cancelDelete();

            return;
        }

        if (Emoji::query()->count() <= 1) {
            Flux::toast(
                heading: __('Cannot delete'),
                text: __('This is the last emoji. Reactions need at least one, so add another first.'),
                variant: 'danger',
            );

            return;
        }

        if (mb_strtolower(mb_trim($confirmation)) !== 'delete') {
            Flux::toast(
                heading: __('Not deleted'),
                text: __('Type delete to confirm.'),
                variant: 'danger',
            );

            return;
        }

        $emoji = $pending['emoji'];
        $imagePath = $emoji->image_path;

        DB::transaction(function () use ($emoji): void {
            Reaction::query()->where('emoji_id', $emoji->id)->delete();

            $emoji->delete();
        });

        // After the commit, never inside it: object storage takes no part in the transaction, so deleting the
        // artwork within it would destroy the file even if the transaction then rolled back and left the row alive.
        if ($imagePath !== null) {
            Storage::disk(config()->string('filesystems.asset_upload', 'public'))->delete($imagePath);
        }

        $this->cancelDelete();

        $this->recordDeletion($emoji, $pending['reactions']);
    }

    /**
     * The shortcode is free-form once the emoji exists: the vendor map is consulted only when adding, to resolve the
     * glyph and artwork, and reactions reference emoji_id rather than the name. Renaming is therefore safe - it cannot
     * break artwork or orphan a reaction - so staff can prefer ":+1:" over ":thumbsup:" if they want.
     */
    public function updateEmoji(int $emojiId, string $shortcode, string $label, int $sortOrder): void
    {
        $emoji = Emoji::query()->findOrFail($emojiId);

        $shortcode = mb_strtolower(mb_trim($shortcode));
        $label = mb_trim($label);

        if ($label === '') {
            Flux::toast(heading: __('Invalid label'), text: __('A label is required.'), variant: 'danger');

            return;
        }

        if (! $this->shortcodeIsWellFormed($shortcode)) {
            Flux::toast(
                heading: __('Invalid shortcode'),
                text: __('Use up to 50 characters: letters, digits, underscore, plus or hyphen.'),
                variant: 'danger',
            );

            return;
        }

        if (Emoji::query()->where('shortcode', $shortcode)->whereKeyNot($emoji->id)->exists()) {
            Flux::toast(
                heading: __('Shortcode in use'),
                text: __('":shortcode" already belongs to another emoji.', ['shortcode' => $shortcode]),
                variant: 'danger',
            );

            return;
        }

        $emoji->update([
            'shortcode' => $shortcode,
            'label' => $label,
            'sort_order' => max(0, min(65535, $sortOrder)),
        ]);

        $this->recordChange('updated', $emoji);
    }

    /**
     * Shortcodes reach markup as :name: and as data-test hooks, so the character set is kept tight. It matches what
     * the vendor map already uses, which includes forms like "+1", "8ball" and "1st_place_medal".
     */
    private function shortcodeIsWellFormed(string $shortcode): bool
    {
        return $shortcode !== ''
            && mb_strlen($shortcode) <= 50
            && preg_match('/^[a-z0-9_+-]+$/', $shortcode) === 1;
    }

    public function addEmoji(string $shortcode): void
    {
        $shortcode = mb_strtolower(mb_trim($shortcode));
        $glyph = $this->knownEmoji()[$shortcode] ?? null;

        if ($glyph === null) {
            Flux::toast(
                heading: __('Unknown emoji'),
                text: __('":shortcode" is not a known emoji shortcode.', ['shortcode' => $shortcode]),
                variant: 'danger',
            );

            return;
        }

        if (Emoji::query()->where('shortcode', $shortcode)->exists()) {
            Flux::toast(heading: __('Already listed'), text: __('That emoji is already on the list.'), variant: 'danger');

            return;
        }

        $codepoints = $this->twemojiCodepoints($glyph);

        $existing = Emoji::query()->where('codepoints', $codepoints)->first();

        if ($existing instanceof Emoji) {
            Flux::toast(
                heading: __('Already listed'),
                text: __('That emoji is already on the list as ":shortcode".', ['shortcode' => $existing->shortcode]),
                variant: 'danger',
            );

            return;
        }

        if (! is_file(public_path('vendor/twemoji/svg/'.$codepoints.'.svg'))) {
            Flux::toast(
                heading: __('No artwork'),
                text: __('There is no Twemoji SVG for that emoji, so it cannot be offered.'),
                variant: 'danger',
            );

            return;
        }

        $emoji = Emoji::query()->create([
            'shortcode' => $shortcode,
            'glyph' => $glyph,
            'codepoints' => $codepoints,
            'label' => ucfirst(str_replace('_', ' ', $shortcode)),
            'sort_order' => $this->nextSortOrder(),
            'enabled' => true,
        ]);

        $this->recordChange('added', $emoji);
    }

    /**
     * No JPEG: emoji need transparency. No SVG: it is an XML document that can carry scripts, and no upload path in
     * this codebase accepts one. ProcessableAnimation is the rule avatars already use - it pings the file without
     * decoding pixel data, so a decompression bomb is refused before any processing happens.
     *
     * @return array<string, array<int, mixed>>
     */
    private function uploadRules(): array
    {
        return [
            'upload' => [
                'required',
                'mimes:png,webp,gif',
                'max:512',
                'dimensions:min_width=32,min_height=32,max_width=1024,max_height=1024',
                new ProcessableAnimation,
            ],
        ];
    }

    /**
     * Add staff-uploaded artwork as an emoji.
     *
     * The cheap checks run first so a bad shortcode is reported without touching the image processor at all, and the
     * processor's availability is confirmed before validation, because ProcessableAnimation instantiates Imagick and
     * would otherwise fail with a class-not-found error rather than an explanation.
     *
     * Nothing is stored until the image has been normalised, so a file that cannot be processed leaves behind
     * neither a row nor an orphaned object.
     */
    public function addCustomEmoji(): void
    {
        abort_unless((bool) auth()->user()?->isAdmin(), 403, 'Access denied. Staff privileges required.');

        $shortcode = mb_strtolower(mb_trim($this->uploadShortcode));
        $label = mb_trim($this->uploadLabel);

        if (! $this->shortcodeIsWellFormed($shortcode)) {
            Flux::toast(
                heading: __('Invalid shortcode'),
                text: __('Use up to 50 characters: letters, digits, underscore, plus or hyphen.'),
                variant: 'danger',
            );

            return;
        }

        if ($label === '') {
            Flux::toast(heading: __('Invalid label'), text: __('A label is required.'), variant: 'danger');

            return;
        }

        if (Emoji::query()->where('shortcode', $shortcode)->exists()) {
            Flux::toast(
                heading: __('Already listed'),
                text: __('That shortcode is already in use.'),
                variant: 'danger',
            );

            return;
        }

        if (! extension_loaded('imagick')) {
            Flux::toast(
                heading: __('Unavailable'),
                text: __('Image processing is not available on this server, so artwork cannot be uploaded.'),
                variant: 'danger',
            );

            return;
        }

        // Livewire uploads the file in a request of its own, so pressing Upload while that is still in flight
        // arrives here with nothing set. The button is disabled during the upload, and this says plainly what
        // happened if one gets through anyway - "the upload field is required" reads like a bug rather than a race.
        $upload = $this->upload;

        if (! $upload instanceof TemporaryUploadedFile) {
            Flux::toast(
                heading: __('No artwork'),
                text: __('Choose an image and wait for it to finish uploading before pressing Upload.'),
                variant: 'danger',
            );

            return;
        }

        $this->validate($this->uploadRules());

        $blob = $upload->get();

        if ($blob === false) {
            Flux::toast(heading: __('Upload failed'), text: __('That file could not be read.'), variant: 'danger');

            return;
        }

        $normalized = resolve(ThumbnailService::class)->normalizeEmoji($blob);

        if ($normalized === null) {
            Flux::toast(
                heading: __('Could not process'),
                text: __('That image could not be processed. Try a different file.'),
                variant: 'danger',
            );

            return;
        }

        $hash = hash('sha256', $normalized);
        $existing = Emoji::query()->where('image_hash', $hash)->first();

        if ($existing instanceof Emoji) {
            Flux::toast(
                heading: __('Already listed'),
                text: __('That artwork is already on the list as ":shortcode".', ['shortcode' => $existing->shortcode]),
                variant: 'danger',
            );

            return;
        }

        $storage = Storage::disk(config()->string('filesystems.asset_upload', 'public'));

        do {
            $path = 'emoji/'.Str::random(40).'.webp';
        } while ($storage->exists($path));

        $storage->put($path, $normalized, 'public');

        $emoji = Emoji::query()->create([
            'shortcode' => $shortcode,
            'glyph' => null,
            'codepoints' => null,
            'image_path' => $path,
            'image_hash' => $hash,
            'label' => $label,
            'sort_order' => $this->nextSortOrder(),
            'enabled' => true,
        ]);

        $this->reset('upload', 'uploadShortcode', 'uploadLabel');

        $this->recordChange('uploaded', $emoji);
    }

    /**
     * The next free display position. max() is untyped, so it is narrowed rather than cast.
     */
    private function nextSortOrder(): int
    {
        $highest = Emoji::query()->max('sort_order');

        return is_numeric($highest) ? (int) $highest + 1 : 1;
    }

    /**
     * Twemoji's filename convention: lowercase hex codepoints joined by '-', with U+FE0F dropped unless the sequence
     * is a keycap, which keeps its selector. This is why emojis.codepoints is stored rather than derived at render
     * time - a heart is U+2764 U+FE0F but its artwork is 2764.svg.
     */
    private function twemojiCodepoints(string $glyph): string
    {
        $characters = preg_split('//u', $glyph, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            return '';
        }

        $points = array_map(static fn (string $character): int => (int) mb_ord($character), $characters);

        $isKeycap = in_array(0x20E3, $points, true);

        $points = array_filter($points, static fn (int $point): bool => $isKeycap || $point !== 0xFE0F);

        return implode('-', array_map(static fn (int $point): string => dechex($point), $points));
    }

    /**
     * Bust the cached whitelist, refresh the panes, and log who changed what. No written reason is demanded: the
     * mandatory-reason pattern in App\Actions\Staff exists for actions taken against a specific user's account,
     * whereas this is site configuration.
     */
    private function recordChange(string $action, Emoji $emoji): void
    {
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        unset($this->whitelist, $this->candidates);

        Track::event(TrackingEventType::EMOJI_UPDATE, null, [
            'action' => $action,
            'shortcode' => $emoji->shortcode,
            'label' => $emoji->label,
            'sort_order' => $emoji->sort_order,
            'enabled' => $emoji->enabled,
        ]);

        Flux::toast(heading: __('Saved'), text: __('The reaction emoji list has been updated.'));
    }

    /**
     * Deletion gets its own event type rather than another EMOJI_UPDATE: it is the one irreversible action here, and
     * the number of reactions it took with it is the part worth being able to search for later.
     */
    private function recordDeletion(Emoji $emoji, int $reactions): void
    {
        resolve(ReactionSummaryService::class)->forgetWhitelist();

        unset($this->whitelist, $this->candidates, $this->pendingDeletion);

        Track::event(TrackingEventType::EMOJI_DELETE, null, [
            'shortcode' => $emoji->shortcode,
            'label' => $emoji->label,
            'codepoints' => $emoji->codepoints,
            'reactions_deleted' => $reactions,
        ]);

        Flux::toast(
            heading: __('Emoji deleted'),
            text: $reactions === 0
                ? __('":shortcode" has been deleted.', ['shortcode' => $emoji->shortcode])
                : __('":shortcode" has been deleted, along with :count reactions.', [
                    'shortcode' => $emoji->shortcode,
                    'count' => number_format($reactions),
                ]),
        );
    }
};
