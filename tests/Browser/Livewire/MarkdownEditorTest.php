<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\License;
use App\Models\Mod;
use App\Models\ModCategory;
use App\Models\ModVersion;
use App\Models\SourceCodeLink;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();

    config(['honeypot.enabled' => false]);

    SptVersion::factory()->create(['version' => '1.0.0']);
});

/**
 * A mod whose owner can open the edit page and save it. The form refuses to save without a source code link.
 */
function editableMod(string $description): Mod
{
    $owner = User::factory()->withMfa()->create();
    ModCategory::factory()->create();

    $mod = Mod::factory()->for($owner, 'owner')->create([
        'description' => $description,
        'license_id' => License::factory()->create()->id,
    ]);

    SourceCodeLink::factory()->create([
        'sourceable_type' => Mod::class,
        'sourceable_id' => $mod->id,
    ]);

    return $mod;
}

describe('Markdown editor', function (): void {
    it('shows the stored description and saves what is typed', function (): void {
        $mod = editableMod('The original description.');
        $field = 'textarea[name="description"]';

        $this->actingAs($mod->owner);

        visit(route('mod.edit', ['modId' => $mod->id]))
            ->assertValue($field, 'The original description.')
            ->clear($field)
            ->type($field, 'A rewritten description.')
            ->click('Update Mod')
            // Only a committed save redirects to the mod page.
            ->assertPathIs(route('mod.show', [$mod->id, $mod->slug], absolute: false))
            ->assertNoJavaScriptErrors();

        expect($mod->fresh()->description)->toBe('A rewritten description.');
    });

    it('wraps the selection in bold from the toolbar', function (): void {
        $mod = editableMod('placeholder');
        $field = 'textarea[name="description"]';

        $this->actingAs($mod->owner);

        $page = visit(route('mod.edit', ['modId' => $mod->id]))
            ->clear($field)
            ->type($field, 'make me bold');

        $page->script("document.querySelector('textarea[name=\"description\"]').select()");

        $page->click('[data-button="bold"]')
            ->assertValue($field, '**make me bold**')
            ->click('Update Mod')
            ->assertPathIs(route('mod.show', [$mod->id, $mod->slug], absolute: false))
            ->assertNoJavaScriptErrors();

        expect($mod->fresh()->description)->toBe('**make me bold**');
    });

    it('lets the emoji menu take Enter on a list line without continuing the list', function (): void {
        $mod = Mod::factory()->create(['published_at' => now()->subHour()]);
        ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '1.0.0']);

        $this->actingAs(User::factory()->create());

        visit($mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('Post Comment')
            ->type('@new-comment-body', '- Nice :fi')
            ->assertVisible('@emoji-autocomplete')
            ->keys('@new-comment-body', ['Enter'])
            ->assertMissing('@emoji-autocomplete')
            // Had the key reached the editor as well, it would have started "- " on a new line.
            ->assertValue('@new-comment-body', '- Nice :fire: ')
            ->assertNoJavaScriptErrors();
    });

    it('gives each comment form its own editor and lets go of it when the form closes', function (): void {
        $user = User::factory()->create();
        $mod = Mod::factory()->create();
        ModVersion::factory()->create(['mod_id' => $mod->id, 'spt_version_constraint' => '1.0.0']);

        $first = Comment::factory()->create([
            'commentable_id' => $mod->id,
            'commentable_type' => Mod::class,
            'user_id' => $user->id,
            'body' => 'The first comment on this mod.',
        ]);

        $second = Comment::factory()->create([
            'commentable_id' => $mod->id,
            'commentable_type' => Mod::class,
            'user_id' => $user->id,
            'body' => 'The second comment, which gets edited.',
            'created_at' => now(),
        ]);

        $this->actingAs($user);

        $page = visit($mod->detail_url.'#comments')
            ->on()->desktop()
            ->waitForText('The first comment on this mod.');

        $page->click('@reply-button-'.$first->id)
            ->waitForText('Reply To Comment')
            ->type('@reply-body-'.$first->id, 'A reply that is never posted.')
            ->click('@cancel-reply-body-'.$first->id)
            ->assertNotPresent('@reply-body-'.$first->id);

        $page->click('@edit-button-'.$second->id)
            ->assertValue('@edit-body-'.$second->id, 'The second comment, which gets edited.')
            ->clear('@edit-body-'.$second->id)
            ->type('@edit-body-'.$second->id, 'The second comment, now edited.')
            ->press('Update Comment');

        waitForWrite($page, fn (): bool => $second->fresh()->body === 'The second comment, now edited.');

        expect($second->fresh()->body)->toBe('The second comment, now edited.');

        // The abandoned reply's editor must be gone, not still reacting to Livewire.
        $page->assertNoJavaScriptErrors();
    });

    it('saves the profile About text', function (): void {
        $user = User::factory()->create(['about' => null]);

        $this->actingAs($user);

        $page = visit(route('profile.show'))
            ->type('textarea[name="about"]', 'I make mods about bears.')
            ->click('form[wire\:submit="updateProfileInformation"] button[type="submit"]');

        waitForWrite($page, fn (): bool => $user->fresh()->about === 'I make mods about bears.');

        expect($user->fresh()->about)->toBe('I make mods about bears.');

        $page->assertNoJavaScriptErrors();
    });
});
