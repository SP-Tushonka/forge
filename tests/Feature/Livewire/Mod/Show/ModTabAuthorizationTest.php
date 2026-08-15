<?php

declare(strict_types=1);

use App\Models\Comment;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;
use App\Models\UserRole;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Unit tests around leaked data for unpublished mods via post requests
 */
beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());

    // A mod a guest can genuinely view: published, with a published version.
    $this->publicMod = Mod::factory()->create([
        'description' => '# Public Body',
        'published_at' => now()->subHour(),
    ]);
    ModVersion::factory()->recycle($this->publicMod)->create(['spt_version_constraint' => '1.0.0']);

    // A mod that was public and has since been disabled — the takedown scenario.
    $this->disabledMod = Mod::factory()->disabled()->create([
        'description' => '# Secret Disabled Body',
        'published_at' => now()->subHour(),
    ]);
    ModVersion::factory()->recycle($this->disabledMod)->create(['spt_version_constraint' => '1.0.0']);
});

$tabs = [
    'mod.show.description-tab',
    'mod.show.versions-tab',
    'mod.show.addons-tab',
    'mod.show.comments-tab',
];

describe('lazy tab authorization', function () use ($tabs): void {
    it('forbids a guest mounting the tab against a disabled mod', function (string $tab): void {
        Livewire::withoutLazyLoading()
            ->test($tab, ['modId' => $this->disabledMod->id])
            ->assertForbidden();
    })->with($tabs);

    it('rejects swapping modId to a disabled mod after mount', function (string $tab): void {
        // The original snapshot is for a public mod (valid checksum); the attacker
        // then sends updates:{modId: <disabled>}. #[Locked] must reject the update.
        $component = Livewire::withoutLazyLoading()
            ->test($tab, ['modId' => $this->publicMod->id])
            ->assertSuccessful();

        expect(fn () => $component->set('modId', $this->disabledMod->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    })->with($tabs);

    it('still serves a public mod to a guest', function (string $tab): void {
        Livewire::withoutLazyLoading()
            ->test($tab, ['modId' => $this->publicMod->id])
            ->assertSuccessful();
    })->with($tabs);

    it('serves a disabled mod to a moderator', function (string $tab): void {
        $moderator = User::factory()->create([
            'user_role_id' => UserRole::factory()->create(['name' => 'moderator'])->id,
        ]);

        Livewire::withoutLazyLoading()
            ->actingAs($moderator)
            ->test($tab, ['modId' => $this->disabledMod->id])
            ->assertSuccessful();
    })->with($tabs);

    // Guards the trait hydrate hook, whose name is derived from the trait basename and so fails silently if renamed.
    it('stops serving a snapshot minted before the mod was disabled', function (string $tab): void {
        $component = Livewire::withoutLazyLoading()
            ->test($tab, ['modId' => $this->publicMod->id])
            ->assertSuccessful();

        $this->publicMod->update(['disabled' => true]);

        $component->refresh()->assertForbidden();
    })->with($tabs);
});

describe('mod page shell authorization', function (): void {
    it('forbids a guest mounting a disabled mod', function (): void {
        Livewire::test('pages::mod.show', ['modId' => $this->disabledMod->id, 'slug' => $this->disabledMod->slug])
            ->assertForbidden();
    });

    it('stops serving a shell snapshot minted before the mod was disabled', function (): void {
        $component = Livewire::test('pages::mod.show', ['modId' => $this->publicMod->id, 'slug' => $this->publicMod->slug])
            ->assertSuccessful();

        $this->publicMod->update(['disabled' => true]);

        $component->refresh()->assertForbidden();
    });

    it('stops serving a comment thread snapshot minted before the mod was disabled', function (): void {
        $component = Livewire::test('comment-component', ['commentable' => $this->publicMod])
            ->assertSuccessful();

        $this->publicMod->update(['disabled' => true]);

        $component->refresh()->assertForbidden();
    });

    // The modal's ids are only ever set by openVersionModal(), which authorizes; unlocked they let a guest
    // read any comment revision on the site straight out of the computed getters.
    it('rejects pointing the version modal at an arbitrary comment revision', function (): void {
        $comment = Comment::factory()->create([
            'user_id' => User::factory()->create()->id,
            'commentable_id' => $this->publicMod->id,
            'commentable_type' => Mod::class,
            'edited_at' => now(),
        ]);
        $version = $comment->versions()->create([
            'body' => 'Redacted secret',
            'version_number' => 1,
            'created_at' => now(),
        ]);

        $component = Livewire::test('comment-component', ['commentable' => $this->publicMod]);

        expect(fn () => $component->set('viewingVersionId', $version->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
        expect(fn () => $component->set('viewingVersionCommentId', $comment->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    it('rejects swapping the mod after mount', function (): void {
        $component = Livewire::test('pages::mod.show', ['modId' => $this->publicMod->id, 'slug' => $this->publicMod->slug])
            ->assertSuccessful();

        expect(fn () => $component->set('mod', $this->disabledMod))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });
});
