<?php

declare(strict_types=1);

use App\Enums\TrackingEventType;
use App\Models\Emoji;
use App\Models\Mod;
use App\Models\Reaction;
use App\Models\TrackingEvent;
use App\Models\User;
use App\Services\ReactionSummaryService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutDefer();

    $this->staff = User::factory()->admin()->create();
});

/**
 * Flux toasts are Livewire browser events, so the heading is how a test can tell which guard rejected an upload.
 * Asserting only that no row appeared would pass just as happily if a completely different guard fired first -
 * which is exactly what happens locally, where the image processor is missing and its guard catches everything.
 */
function expectToastHeading(\Livewire\Features\SupportTesting\Testable $component, string $heading): void
{
    $component->assertDispatched(
        'toast-show',
        fn (string $event, array $params): bool => ($params['slots']['heading'] ?? null) === $heading,
    );
}

describe('EmojiTool access', function (): void {
    it('refuses a non staff user', function (): void {
        Livewire::actingAs(User::factory()->create())
            ->test('admin.staff-tools.emoji-tool')
            ->assertForbidden();
    });

    it('refuses a moderator', function (): void {
        Livewire::actingAs(User::factory()->moderator()->create())
            ->test('admin.staff-tools.emoji-tool')
            ->assertForbidden();
    });

    it('allows an administrator', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->assertSuccessful();
    });
});

describe('EmojiTool editing', function (): void {
    it('disables an emoji without deleting it or its reactions', function (): void {
        $tada = Emoji::query()->where('shortcode', 'tada')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleEnabled', $tada->id)
            ->assertSuccessful();

        expect($tada->fresh()->enabled)->toBeFalse()
            ->and(Emoji::query()->count())->toBe(5);
    });

    it('re-enables a disabled emoji', function (): void {
        $tada = Emoji::query()->where('shortcode', 'tada')->sole();
        $tada->update(['enabled' => false]);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleEnabled', $tada->id);

        expect($tada->fresh()->enabled)->toBeTrue();
    });

    it('adds an emoji from a shortcode', function (): void {
        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('addEmoji', 'rocket')
            ->assertSuccessful();

        // Asserted here rather than in the browser: dispatch is deterministic at this layer, whereas the rendered
        // toast has gone missing entirely under CI load without the action itself ever failing.
        expectToastHeading($component, 'Saved');

        $added = Emoji::query()->where('shortcode', 'rocket')->sole();

        expect($added->codepoints)->toBe('1f680')
            ->and($added->glyph)->toBe('🚀')
            ->and($added->enabled)->toBeTrue()
            ->and($added->sort_order)->toBe(6);
    });

    it('adds a numeric shortcode, whose array key PHP stores as an integer', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('addEmoji', '100')
            ->assertSuccessful();

        $added = Emoji::query()->where('shortcode', '100')->sole();

        expect($added->codepoints)->toBe('1f4af')
            ->and($added->glyph)->toBe('💯');
    });

    it('refuses a shortcode that is not a known emoji', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('addEmoji', 'definitely_not_an_emoji');

        expect(Emoji::query()->count())->toBe(5);
    });

    it('refuses a shortcode that is already whitelisted', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('addEmoji', 'heart');

        expect(Emoji::query()->where('shortcode', 'heart')->count())->toBe(1)
            ->and(Emoji::query()->count())->toBe(5);
    });

    it('refuses an emoji already whitelisted under a different name', function (): void {
        // The reported case: rename :1st_place_medal: to :gold:, then try to re-add it by its vendor name.
        $medal = Emoji::query()->where('shortcode', 'thumbsup')->sole();
        $medal->update(['shortcode' => 'gold']);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('addEmoji', 'thumbsup');

        expect(Emoji::query()->where('codepoints', '1f44d')->count())->toBe(1)
            ->and(Emoji::query()->count())->toBe(5);
    });

    it('drops an already-whitelisted emoji from the picker whatever it was renamed to', function (): void {
        $component = Livewire::actingAs($this->staff)->test('admin.staff-tools.emoji-tool');

        expect(array_column($component->instance()->candidates, 'shortcode'))->not->toContain('thumbsup');

        // Renaming must not make the same glyph offerable again under its vendor name.
        Emoji::query()->where('shortcode', 'thumbsup')->sole()->update(['shortcode' => 'gold']);

        $component = Livewire::actingAs($this->staff)->test('admin.staff-tools.emoji-tool');

        expect(array_column($component->instance()->candidates, 'shortcode'))->not->toContain('thumbsup');
    });

    it('rejects a duplicate emoji at the database level too', function (): void {
        expect(fn () => Emoji::query()->create([
            'shortcode' => 'another_thumbsup',
            'glyph' => '👍',
            'codepoints' => '1f44d',
            'label' => 'Another thumbs up',
            'sort_order' => 90,
            'enabled' => true,
        ]))->toThrow(Illuminate\Database\QueryException::class);
    });

    it('updates the shortcode, label and sort order', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $fire->id, 'blazing', 'Blazing', 9);

        expectToastHeading($component, 'Saved');

        $fire->refresh();

        expect($fire->shortcode)->toBe('blazing')
            ->and($fire->label)->toBe('Blazing')
            ->and($fire->sort_order)->toBe(9);
    });

    it('renames thumbsup to the +1 alias staff may prefer', function (): void {
        $thumbsup = Emoji::query()->where('shortcode', 'thumbsup')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $thumbsup->id, '+1', 'Thumbs up', 2);

        $thumbsup->refresh();

        // Renaming must leave the artwork and the reactions pointing at it untouched.
        expect($thumbsup->shortcode)->toBe('+1')
            ->and($thumbsup->codepoints)->toBe('1f44d')
            ->and($thumbsup->glyph)->toBe('👍');
    });

    it('keeps reactions attached when an emoji is renamed', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $fire->id, 'flames', 'Flames', 4);

        expect(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(1)
            ->and($fire->fresh()->shortcode)->toBe('flames');
    });

    it('lowercases and trims a shortcode', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $fire->id, '  BLAZING  ', 'Blazing', 4);

        expect($fire->fresh()->shortcode)->toBe('blazing');
    });

    it('refuses a shortcode already used by another emoji', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $fire->id, 'heart', 'Fire', 4);

        // The browser cannot cover this: a refused rename leaves the page byte-identical, so the toast was its only
        // observable - and that made the test hostage to a toast that intermittently never rendered.
        expectToastHeading($component, 'Shortcode in use');

        expect($fire->fresh()->shortcode)->toBe('fire');
    });

    it('accepts an emoji keeping its own shortcode unchanged', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $fire->id, 'fire', 'Blazing', 4);

        expect($fire->fresh()->label)->toBe('Blazing');
    });

    it('refuses a shortcode with characters that would break markup', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        foreach (['', '   ', 'has space', 'quote"mark', 'angle<bracket', str_repeat('a', 51)] as $bad) {
            Livewire::actingAs($this->staff)
                ->test('admin.staff-tools.emoji-tool')
                ->call('updateEmoji', $fire->id, $bad, 'Fire', 4);
        }

        expect($fire->fresh()->shortcode)->toBe('fire');
    });

    it('refuses an empty label', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('updateEmoji', $fire->id, 'fire', '   ', 9);

        expect($fire->fresh()->label)->toBe('Fire');
    });

    it('writes a tracking event for every change', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleEnabled', Emoji::query()->where('shortcode', 'tada')->sole()->id);

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::EMOJI_UPDATE->value)
            ->sole();

        expect($event->event_data)->toMatchArray([
            'action' => 'disabled',
            'shortcode' => 'tada',
        ]);
    });

    it('busts the cached whitelist after a change', function (): void {
        resolve(ReactionSummaryService::class)->whitelist();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleEnabled', Emoji::query()->where('shortcode', 'tada')->sole()->id);

        expect(resolve(ReactionSummaryService::class)->whitelist()->pluck('shortcode')->all())
            ->not->toContain('tada');
    });

    it('offers the whole catalogue rather than a capped page of it', function (): void {
        $component = Livewire::actingAs($this->staff)->test('admin.staff-tools.emoji-tool');

        $candidates = $component->instance()->candidates;
        $shortcodes = array_column($candidates, 'shortcode');

        // Every vendor shortcode that has artwork and is not already whitelisted, not a truncated slice of them.
        expect(count($candidates))->toBeGreaterThan(1000)
            ->and($shortcodes)->toContain('rocket')
            ->and($shortcodes)->not->toContain('heart');
    });

    it('orders the catalogue by codepoint so related emoji sit together', function (): void {
        $component = Livewire::actingAs($this->staff)->test('admin.staff-tools.emoji-tool');

        $order = array_map(
            fn (array $c): int => (int) hexdec(explode('-', $c['codepoints'])[0]),
            $component->instance()->candidates,
        );

        $sorted = $order;
        sort($sorted);

        expect($order)->toBe($sorted);
    });

    it('leaves out emoji that have no twemoji artwork', function (): void {
        $component = Livewire::actingAs($this->staff)->test('admin.staff-tools.emoji-tool');

        foreach ($component->instance()->candidates as $candidate) {
            expect(is_file(public_path('vendor/twemoji/svg/'.$candidate['codepoints'].'.svg')))
                ->toBeTrue('Offered '.$candidate['shortcode'].' with no artwork.');
        }
    });
});

describe('EmojiTool deletion', function (): void {
    it('deletes the emoji and every reaction left with it', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id)
            ->call('deleteEmoji', 'delete')
            ->assertSuccessful();

        expectToastHeading($component, 'Emoji deleted');

        // reactions.emoji_id is restrictOnDelete, so this only passes if the rows went inside the transaction.
        expect(Emoji::query()->whereKey($fire->id)->exists())->toBeFalse()
            ->and(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(0);
    });

    it('leaves the other emoji and their reactions alone', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $heart->id]);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id)
            ->call('deleteEmoji', 'delete');

        expect(Emoji::query()->count())->toBe(4)
            ->and(Reaction::query()->where('emoji_id', $heart->id)->count())->toBe(1);
    });

    it('keeps everything when the confirmation is not typed', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id)
            ->call('deleteEmoji', 'yes');

        expect(Emoji::query()->whereKey($fire->id)->exists())->toBeTrue()
            ->and(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(1);
    });

    it('accepts the confirmation in any case, ignoring surrounding space', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id)
            ->call('deleteEmoji', '  DELETE ');

        expect(Emoji::query()->whereKey($fire->id)->exists())->toBeFalse();
    });

    it('refuses to delete the last remaining emoji', function (): void {
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();
        Emoji::query()->whereKeyNot($heart->id)->delete();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $heart->id)
            ->call('deleteEmoji', 'delete');

        expect(Emoji::query()->count())->toBe(1);
    });

    it('stops offering the emoji once it is gone', function (): void {
        $tada = Emoji::query()->where('shortcode', 'tada')->sole();

        // Warm the cache first: deleting has to bust it, not merely miss it.
        resolve(ReactionSummaryService::class)->whitelist();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $tada->id)
            ->call('deleteEmoji', 'delete');

        expect(resolve(ReactionSummaryService::class)->whitelist()->pluck('shortcode')->all())
            ->not->toContain('tada');
    });

    it('disables the delete button once only one emoji is left', function (): void {
        $button = function (): string {
            $html = Livewire::actingAs($this->staff)->test('admin.staff-tools.emoji-tool')->html();

            preg_match('/<button[^>]*data-test="emoji-delete-heart"[^>]*>/', $html, $matches);

            return $matches[0] ?? '';
        };

        // The attribute, not the string: Flux's class list carries disabled:opacity-75 in both states.
        expect($button())->not->toBe('')->and($button())->not->toContain('disabled="disabled"');

        $heart = Emoji::query()->where('shortcode', 'heart')->sole();
        Emoji::query()->whereKeyNot($heart->id)->delete();

        expect($button())->toContain('disabled="disabled"');
    });

    it('deletes the stored artwork along with a custom emoji', function (): void {
        $disk = config()->string('filesystems.asset_upload', 'public');
        Storage::fake($disk);
        Storage::disk($disk)->put('emoji/party.webp', 'bytes');

        $custom = Emoji::factory()->custom()->create(['shortcode' => 'partyblob', 'image_path' => 'emoji/party.webp']);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $custom->id)
            ->call('deleteEmoji', 'delete');

        expect(Emoji::query()->whereKey($custom->id)->exists())->toBeFalse()
            ->and(Storage::disk($disk)->exists('emoji/party.webp'))->toBeFalse();
    });

    it('leaves storage alone when deleting a twemoji emoji', function (): void {
        $disk = config()->string('filesystems.asset_upload', 'public');
        Storage::fake($disk);
        Storage::disk($disk)->put('emoji/unrelated.webp', 'bytes');

        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id)
            ->call('deleteEmoji', 'delete');

        expect(Storage::disk($disk)->exists('emoji/unrelated.webp'))->toBeTrue();
    });

    it('keeps the artwork when the confirmation is not typed', function (): void {
        $disk = config()->string('filesystems.asset_upload', 'public');
        Storage::fake($disk);
        Storage::disk($disk)->put('emoji/party.webp', 'bytes');

        $custom = Emoji::factory()->custom()->create(['image_path' => 'emoji/party.webp']);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $custom->id)
            ->call('deleteEmoji', 'nope');

        expect(Emoji::query()->whereKey($custom->id)->exists())->toBeTrue()
            ->and(Storage::disk($disk)->exists('emoji/party.webp'))->toBeTrue();
    });

    it('logs the deletion with the number of reactions it destroyed', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        foreach (Mod::factory()->count(2)->create() as $mod) {
            $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);
        }

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id)
            ->call('deleteEmoji', 'delete');

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::EMOJI_DELETE->value)
            ->sole();

        expect($event->event_data)->toMatchArray([
            'shortcode' => 'fire',
            'codepoints' => '1f525',
            'reactions_deleted' => 2,
        ]);
    });
});

describe('EmojiTool deletion warning', function (): void {
    it('counts the reactions that would be lost', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id);

        expect($component->instance()->pendingDeletion['reactions'])->toBe(1);
    });

    it('reports no markdown fallback for a shortcode staff invented', function (): void {
        // :gold: is what staff renamed the medal to. Nothing else claims it, so comments drop back to plain text.
        $gold = Emoji::query()->where('shortcode', 'tada')->sole();
        $gold->update(['shortcode' => 'gold']);

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $gold->id);

        expect($component->instance()->pendingDeletion['fallback'])->toBeNull()
            ->and($component->instance()->pendingDeletion['fallbackMatches'])->toBeFalse();
    });

    it('reports markdown as unaffected for a standard shortcode', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $fire->id);

        expect($component->instance()->pendingDeletion['fallbackMatches'])->toBeTrue();
    });

    it('treats the variation selector as the same emoji', function (): void {
        // The stored heart is U+2764 U+FE0F and the vendor map holds a bare U+2764. Comparing the glyphs themselves
        // would call these different emoji and warn staff that markdown would change, which is false.
        $heart = Emoji::query()->where('shortcode', 'heart')->sole();

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('confirmDelete', $heart->id);

        $pending = $component->instance()->pendingDeletion;

        expect($pending['fallback'])->not->toBe($heart->glyph)
            ->and($pending['fallbackMatches'])->toBeTrue();
    });
});

describe('EmojiTool custom uploads', function (): void {
    // These three sit before the image processor is ever consulted, so they are real coverage locally rather than
    // a guard bailing out early and leaving the assertion true by accident.
    it('refuses a shortcode that is already taken', function (): void {
        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'fire')
            ->set('uploadLabel', 'Flames')
            ->call('addCustomEmoji');

        expectToastHeading($component, 'Already listed');

        expect(Emoji::query()->count())->toBe(5)
            ->and(Emoji::query()->where('shortcode', 'fire')->sole()->isCustom())->toBeFalse();
    });

    it('refuses a malformed shortcode', function (): void {
        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'not a shortcode!')
            ->set('uploadLabel', 'Nope')
            ->call('addCustomEmoji');

        expectToastHeading($component, 'Invalid shortcode');

        expect(Emoji::query()->count())->toBe(5);
    });

    it('refuses a blank label', function (): void {
        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'partyblob')
            ->set('uploadLabel', '   ')
            ->call('addCustomEmoji');

        expectToastHeading($component, 'Invalid label');

        expect(Emoji::query()->count())->toBe(5);
    });

    it('says so when the image processor is unavailable', function (): void {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is present, so the unavailable path cannot be reached.');
        }

        // Without this guard ProcessableAnimation would fatal on "Class Imagick not found" rather than telling
        // staff why the upload cannot proceed.
        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'partyblob')
            ->set('uploadLabel', 'Party blob')
            ->set('upload', Illuminate\Http\UploadedFile::fake()->image('e.png', 128, 128))
            ->call('addCustomEmoji')
            ->assertHasNoErrors();

        expectToastHeading($component, 'Unavailable');

        expect(Emoji::query()->count())->toBe(5);
    });
});

describe('EmojiTool custom upload processing', function (): void {
    beforeEach(function (): void {
        // Everything below decodes an image, so it runs in CI rather than on a machine without imagick.
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is not installed.');
        }

        Storage::fake(config()->string('filesystems.asset_upload', 'public'));
    });

    it('refuses an svg however it is labelled', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'sneaky')
            ->set('uploadLabel', 'Sneaky')
            ->set('upload', Illuminate\Http\UploadedFile::fake()->create('e.svg', 8, 'image/svg+xml'))
            ->call('addCustomEmoji')
            ->assertHasErrors('upload');

        expect(Emoji::query()->count())->toBe(5);
    });

    it('refuses a file over the size cap', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'huge')
            ->set('uploadLabel', 'Huge')
            ->set('upload', Illuminate\Http\UploadedFile::fake()->create('e.png', 2048, 'image/png'))
            ->call('addCustomEmoji')
            ->assertHasErrors('upload');

        expect(Emoji::query()->count())->toBe(5);
    });

    it('stores normalised artwork and adds it to the whitelist', function (): void {
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'PartyBlob')
            ->set('uploadLabel', 'Party blob')
            ->set('upload', Illuminate\Http\UploadedFile::fake()->image('e.png', 256, 256))
            ->call('addCustomEmoji')
            ->assertHasNoErrors();

        $added = Emoji::query()->where('shortcode', 'partyblob')->sole();

        expect($added->isCustom())->toBeTrue()
            ->and($added->codepoints)->toBeNull()
            ->and($added->glyph)->toBeNull()
            ->and($added->image_path)->toEndWith('.webp')
            ->and(Storage::disk(config()->string('filesystems.asset_upload', 'public'))->exists((string) $added->image_path))
            ->toBeTrue();
    });

    it('refuses artwork that is already on the list under another shortcode', function (): void {
        $upload = fn (): Illuminate\Http\UploadedFile => Illuminate\Http\UploadedFile::fake()->image('e.png', 256, 256);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'first')
            ->set('uploadLabel', 'First')
            ->set('upload', $upload())
            ->call('addCustomEmoji');

        // Identity is the hash of the normalised bytes, not the shortcode - the same rule the Twemoji catalogue
        // follows through codepoints.
        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->set('uploadShortcode', 'second')
            ->set('uploadLabel', 'Second')
            ->set('upload', $upload())
            ->call('addCustomEmoji');

        expect(Emoji::query()->where('shortcode', 'first')->exists())->toBeTrue()
            ->and(Emoji::query()->where('shortcode', 'second')->exists())->toBeFalse();
    });
});

describe('EmojiTool surface restrictions', function (): void {
    it('switches a single surface off without touching the others', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        $component = Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleSurface', $fire->id, App\Enums\EmojiSurface::Comments->value)
            ->assertSuccessful();

        expectToastHeading($component, 'Saved');

        $fire->refresh();

        expect($fire->allow_comments)->toBeFalse()
            ->and($fire->allow_mod_reactions)->toBeTrue()
            ->and($fire->allow_mod_description)->toBeTrue()
            ->and($fire->enabled)->toBeTrue();
    });

    it('switches it back on again', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $fire->update(['allow_mod_description' => false]);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleSurface', $fire->id, App\Enums\EmojiSurface::ModDescription->value);

        expect($fire->refresh()->allow_mod_description)->toBeTrue();
    });

    it('ignores a surface it does not recognise', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleSurface', $fire->id, 'everywhere')
            ->assertSuccessful();

        $fire->refresh();

        expect($fire->allow_comments)->toBeTrue()
            ->and($fire->allow_mod_reactions)->toBeTrue()
            ->and($fire->allow_mod_description)->toBeTrue();
    });

    it('busts the cached whitelist so the change takes effect at once', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        // Warm it first: the toggle has to clear the cache, not merely miss it.
        resolve(ReactionSummaryService::class)->whitelistFor(App\Enums\EmojiSurface::Comments);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleSurface', $fire->id, App\Enums\EmojiSurface::Comments->value);

        expect(resolve(ReactionSummaryService::class)->whitelistFor(App\Enums\EmojiSurface::Comments)->pluck('shortcode')->all())
            ->not->toContain('fire');
    });

    it('records the change on the moderation log', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleSurface', $fire->id, App\Enums\EmojiSurface::ModDescription->value);

        $event = TrackingEvent::query()
            ->where('event_name', TrackingEventType::EMOJI_UPDATE->value)
            ->sole();

        expect($event->event_data)->toMatchArray([
            'action' => 'blocked on mod_description',
            'shortcode' => 'fire',
        ]);
    });

    it('never deletes reactions when a surface is switched off', function (): void {
        $fire = Emoji::query()->where('shortcode', 'fire')->sole();
        $mod = Mod::factory()->create();
        $mod->reactions()->create(['user_id' => User::factory()->create()->id, 'emoji_id' => $fire->id]);

        Livewire::actingAs($this->staff)
            ->test('admin.staff-tools.emoji-tool')
            ->call('toggleSurface', $fire->id, App\Enums\EmojiSurface::ModReactions->value);

        expect(Reaction::query()->where('emoji_id', $fire->id)->count())->toBe(1);
    });
});
