<?php

declare(strict_types=1);

use App\Models\Addon;
use App\Models\AddonVersion;
use App\Models\Comment;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('honeypot.enabled', false);

    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());
});

function versionTagMod(string ...$versions): Mod
{
    $mod = Mod::factory()->create(['published_at' => now()->subDay()]);

    foreach ($versions as $version) {
        versionTagModVersion($mod, $version);
    }

    return $mod;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function versionTagModVersion(Mod $mod, string $version, array $attributes = []): ModVersion
{
    return ModVersion::factory()->recycle($mod)->create([
        'version' => $version,
        'spt_version_constraint' => '1.0.0',
        'published_at' => now()->subDay(),
        ...$attributes,
    ]);
}

function versionTagComment(Mod $mod, ?string $version): Comment
{
    return Comment::factory()->create([
        'commentable_id' => $mod->id,
        'commentable_type' => $mod::class,
        'commentable_version' => $version,
        'body' => 'A comment.',
    ]);
}

function postVersionTagComment(Mod|Addon|User $commentable, ?User $author = null): Comment
{
    $author ??= User::factory()->create();

    Livewire::actingAs($author)
        ->test('comment-component', ['commentable' => $commentable])
        ->set('newCommentBody', 'Does this still work?')
        ->call('createComment')
        ->assertHasNoErrors();

    return Comment::query()->where('user_id', $author->id)->sole();
}

describe('stamping', function (): void {
    it('stamps a mod comment with the highest public version', function (): void {
        $mod = versionTagMod('1.0.0', '1.10.0', '1.2.0');

        expect(postVersionTagComment($mod)->commentable_version)->toBe('1.10.0');
    });

    it('ignores unpublished, scheduled and disabled versions even when the owner comments', function (): void {
        $mod = versionTagMod('1.0.0');
        versionTagModVersion($mod, '2.0.0', ['published_at' => null]);
        versionTagModVersion($mod, '3.0.0', ['published_at' => now()->addDay()]);
        versionTagModVersion($mod, '4.0.0', ['disabled' => true]);

        expect(postVersionTagComment($mod, $mod->owner)->commentable_version)->toBe('1.0.0');
    });

    it('ignores versions tied to an unreleased SPT version when staff comment', function (): void {
        $moderator = User::factory()->moderator()->create();
        SptVersion::factory()->publishedAt(now()->addWeek())->create(['version' => '2.0.0']);
        $mod = versionTagMod('1.0.0');

        // Saved by staff, the constraint also links the unreleased SPT version, and staff then count 2.0.0 as visible.
        $this->actingAs($moderator);
        versionTagModVersion($mod, '2.0.0', ['spt_version_constraint' => '2.0.0']);
        expect($mod->versions()->publiclyVisible()->count())->toBe(2);

        expect(postVersionTagComment($mod, $moderator)->commentable_version)->toBe('1.0.0');
    });

    it('falls back to legacy versions when the mod has no SPT tagged ones', function (): void {
        $mod = versionTagMod();
        versionTagModVersion($mod, '0.5.0', ['spt_version_constraint' => '']);

        expect(postVersionTagComment($mod)->commentable_version)->toBe('0.5.0');
    });

    it('prefers SPT tagged versions over a higher legacy version', function (): void {
        $mod = versionTagMod('1.0.0');
        versionTagModVersion($mod, '9.0.0', ['spt_version_constraint' => '']);

        expect(postVersionTagComment($mod)->commentable_version)->toBe('1.0.0');
    });

    it('leaves the stamp empty when the mod has no public version', function (): void {
        $mod = versionTagMod();
        versionTagModVersion($mod, '1.0.0', ['disabled' => true]);

        expect(postVersionTagComment($mod)->commentable_version)->toBeNull();
    });

    it('stamps an addon comment with its highest published version', function (): void {
        $addon = Addon::factory()->published()->create();
        AddonVersion::factory()->recycle($addon)->create(['version' => '1.1.0', 'published_at' => now()->subDay()]);
        AddonVersion::factory()->recycle($addon)->create(['version' => '1.0.0', 'published_at' => now()->subDay()]);
        AddonVersion::factory()->recycle($addon)->create(['version' => '1.2.0', 'published_at' => now()->subDay(), 'disabled' => true]);
        AddonVersion::factory()->recycle($addon)->create(['version' => '1.3.0', 'published_at' => null]);
        AddonVersion::factory()->recycle($addon)->create(['version' => '1.4.0', 'published_at' => now()->addDay()]);

        expect(postVersionTagComment($addon)->commentable_version)->toBe('1.1.0');
    });

    it('does not stamp comments on user profiles', function (): void {
        $profile = User::factory()->create();

        expect(postVersionTagComment($profile)->commentable_version)->toBeNull();
    });

    it('stamps a reply with the version that is latest when the reply is written', function (): void {
        $mod = versionTagMod('1.0.0');
        $parent = versionTagComment($mod, '1.0.0');
        versionTagModVersion($mod, '1.1.0');
        $author = User::factory()->create();

        Livewire::actingAs($author)
            ->test('comment-component', ['commentable' => $mod])
            ->set('formStates.reply-'.$parent->id.'.body', 'Still broken on the new one.')
            ->call('createReply', $parent->id)
            ->assertHasNoErrors();

        expect(Comment::query()->where('user_id', $author->id)->sole()->commentable_version)->toBe('1.1.0')
            ->and($parent->fresh()->commentable_version)->toBe('1.0.0');
    });

    it('keeps the original stamp when the comment is edited after a new release', function (): void {
        $mod = versionTagMod('1.0.0');
        $author = User::factory()->create();
        $comment = postVersionTagComment($mod, $author);
        versionTagModVersion($mod, '2.0.0');

        Livewire::actingAs($author)
            ->test('comment-component', ['commentable' => $mod])
            ->set('formStates.edit-'.$comment->id.'.body', 'Edited after the update.')
            ->call('updateComment', $comment->id)
            ->assertHasNoErrors();

        expect($comment->fresh()->commentable_version)->toBe('1.0.0');
    });
});

describe('display', function (): void {
    it('shows a green tag when the comment was written for the current version', function (): void {
        $mod = versionTagMod('1.2.0');
        $comment = versionTagComment($mod, '1.2.0');

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-test="comment-version-tag-'.$comment->id.'"')
            ->assertSeeHtml('data-color="green"')
            ->assertSee('v1.2.0')
            ->assertDontSee('latest is');
    });

    it('shows an amber tag and the latest version for a comment on an older minor version', function (): void {
        $mod = versionTagMod('1.2.0', '1.3.0');
        versionTagComment($mod, '1.2.0');

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-color="amber"')
            ->assertSee('Written for v1.2.0, latest is v1.3.0');
    });

    it('shows a red tag for a comment on an older major version', function (): void {
        $mod = versionTagMod('1.2.0', '2.0.0');
        versionTagComment($mod, '1.2.0');

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-color="red"');
    });

    it('shows an amber tag when only the label differs', function (): void {
        $mod = versionTagMod('3.0.3+spt4.0');
        versionTagComment($mod, '3.0.3+spt3.11');

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-color="amber"')
            ->assertSee('Written for v3.0.3+spt3.11, latest is v3.0.3+spt4.0');
    });

    it('shows the tag on replies', function (): void {
        $mod = versionTagMod('1.0.0', '1.1.0');
        $root = versionTagComment($mod, '1.0.0');
        $reply = Comment::factory()->create([
            'commentable_id' => $mod->id,
            'commentable_type' => $mod::class,
            'parent_id' => $root->id,
            'root_id' => $root->id,
            'commentable_version' => '1.1.0',
            'body' => 'A reply.',
        ]);

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-test="comment-version-tag-'.$reply->id.'"');
    });

    it('uses the colours the mod owner chose', function (): void {
        $mod = versionTagMod('1.2.0', '1.2.1');
        $mod->update(['comment_version_colors' => ['patch' => 'neutral']]);
        versionTagComment($mod, '1.2.0');

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-color="neutral"')
            ->assertSee('Written for v1.2.0, latest is v1.2.1');
    });

    it('shows a neutral tag without a comparison when the mod no longer has a public version', function (): void {
        $mod = versionTagMod();
        versionTagModVersion($mod, '1.2.0', ['disabled' => true]);
        versionTagComment($mod, '1.2.0');

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertSeeHtml('data-color="neutral"')
            ->assertSee('Written for v1.2.0')
            ->assertDontSee('latest is');
    });

    it('shows no tag on comments written before stamping existed', function (): void {
        $mod = versionTagMod('1.2.0');
        $comment = versionTagComment($mod, null);

        Livewire::test('comment-component', ['commentable' => $mod])
            ->assertDontSeeHtml('data-test="comment-version-tag-'.$comment->id.'"');
    });
});

describe('mod edit page', function (): void {
    it('shows the defaults for a mod without its own colours', function (): void {
        $mod = versionTagMod('1.0.0');

        Livewire::actingAs($mod->owner)
            ->test('pages::mod.edit', ['modId' => $mod->id])
            ->assertSet('commentVersionColors', ['major' => 'red', 'minor' => 'amber', 'patch' => 'amber']);
    });

    it('loads the colours the owner saved', function (): void {
        $mod = versionTagMod('1.0.0');
        $mod->update(['comment_version_colors' => ['minor' => 'red']]);

        Livewire::actingAs($mod->owner)
            ->test('pages::mod.edit', ['modId' => $mod->id])
            ->assertSet('commentVersionColors.minor', 'red')
            ->assertSet('commentVersionColors.major', 'red');
    });

    it('stores only the colours that differ from the defaults', function (): void {
        $mod = versionTagMod('1.0.0');

        Livewire::actingAs($mod->owner)
            ->test('pages::mod.edit', ['modId' => $mod->id])
            ->set('commentVersionColors.patch', 'green')
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->comment_version_colors)->toBe(['patch' => 'green']);
    });

    it('clears the stored colours when the owner goes back to the defaults', function (): void {
        $mod = versionTagMod('1.0.0');
        $mod->update(['comment_version_colors' => ['patch' => 'neutral']]);

        Livewire::actingAs($mod->owner)
            ->test('pages::mod.edit', ['modId' => $mod->id])
            ->set('commentVersionColors.patch', 'amber')
            ->call('save')
            ->assertHasNoErrors();

        expect($mod->fresh()->comment_version_colors)->toBeNull();
    });

    it('rejects a colour outside the fixed choices', function (): void {
        $mod = versionTagMod('1.0.0');

        Livewire::actingAs($mod->owner)
            ->test('pages::mod.edit', ['modId' => $mod->id])
            ->set('commentVersionColors.major', '#ff00ff')
            ->call('save')
            ->assertHasErrors('commentVersionColors.major');
    });

    it('rejects extra keys', function (): void {
        $mod = versionTagMod('1.0.0');

        Livewire::actingAs($mod->owner)
            ->test('pages::mod.edit', ['modId' => $mod->id])
            ->set('commentVersionColors.hotfix', 'red')
            ->call('save')
            ->assertHasErrors('commentVersionColors');
    });

    it('lets staff change the colours from the staff mod tool', function (): void {
        Notification::fake();
        $mod = versionTagMod('1.0.0');

        Livewire::actingAs(User::factory()->admin()->create())
            ->test('admin.staff-tools.mod-tool', ['modId' => $mod->id])
            ->set('commentVersionColors.major', 'amber')
            ->set('reason', 'Owner asked for softer tags')
            ->call('saveDetails')
            ->assertHasNoErrors();

        expect($mod->fresh()->comment_version_colors)->toBe(['major' => 'amber']);
    });
});
