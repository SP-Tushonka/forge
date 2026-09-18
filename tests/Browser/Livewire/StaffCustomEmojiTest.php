<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    $this->staff = User::factory()->admin()->create();
});

describe('Staff custom emoji', function (): void {
    it('marks custom rows in the whitelist table', function (): void {
        Emoji::factory()->custom()->create(['shortcode' => 'partyblob', 'label' => 'Party blob']);

        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Reaction emoji')
            ->assertPresent('@emoji-custom-badge-partyblob')
            ->assertMissing('@emoji-custom-badge-fire')
            ->assertNoJavaScriptErrors();
    });

    it('shows the upload pane', function (): void {
        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Upload a custom emoji')
            ->assertPresent('@custom-emoji-shortcode')
            ->assertPresent('@custom-emoji-file')
            ->assertPresent('@custom-emoji-submit')
            ->assertNoJavaScriptErrors();
    });

    it('blocks the upload button while a file is still uploading', function (): void {
        // The original bug: clicking Upload a second before the file finished sent an empty property, and the
        // validator then reported the artwork as missing. Livewire dispatches these events on the input and they
        // bubble, so firing them by hand exercises the same guard a real upload does - no multipart needed. The
        // detail payload is required: Livewire's own listener destructures detail.id and throws without it.
        $this->actingAs($this->staff);

        $page = visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Upload a custom emoji');

        $disabled = <<<'JS'
            document.querySelector('[data-test="custom-emoji-submit"]').disabled
            JS;

        $start = <<<'JS'
            (() => {
                document.querySelector('[data-test="custom-emoji-file"]')
                    .dispatchEvent(new CustomEvent('livewire-upload-start', { bubbles: true, detail: { id: 'probe' } }));

                return true;
            })()
            JS;

        $finish = <<<'JS'
            (() => {
                document.querySelector('[data-test="custom-emoji-file"]')
                    .dispatchEvent(new CustomEvent('livewire-upload-finish', { bubbles: true, detail: { id: 'probe' } }));

                return true;
            })()
            JS;

        $page->assertScript($disabled, false)
            ->assertScript($start, true)
            ->assertScript($disabled, true)
            ->assertScript($finish, true)
            ->assertScript($disabled, false)
            ->assertNoJavaScriptErrors();
    });

    it('drives the form through to validation', function (): void {
        // The pest browser server does not forward multipart file uploads - the same limitation documented in
        // ImageCropUploadTest - so the file itself cannot travel. Submitting without one still exercises the real
        // wiring: the inputs bind, the button reaches addCustomEmoji, and the guard runs. Storing normalised artwork
        // is covered by the feature test, which uses a genuine UploadedFile.
        $this->actingAs($this->staff);

        visit(route('admin.staff-tools').'#reactions')
            ->on()->desktop()
            ->waitForText('Upload a custom emoji')
            ->type('@custom-emoji-shortcode', 'partyblob')
            ->type('@custom-emoji-label', 'Party blob')
            ->click('@custom-emoji-submit')
            ->waitForText('No artwork')
            ->assertNoJavaScriptErrors();

        expect(Emoji::query()->where('shortcode', 'partyblob')->exists())->toBeFalse();
    });
});
