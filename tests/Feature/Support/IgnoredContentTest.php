<?php

declare(strict_types=1);

use App\Models\Addon;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModList;
use App\Models\User;
use App\Support\Api\V0\PublicViewpoint;
use App\Support\IgnoredContent;

beforeEach(function (): void {
    $this->viewer = User::factory()->create();
    $this->author = User::factory()->create();
});

describe('hidden mods', function (): void {
    it('hides a directly ignored mod', function (): void {
        $mod = Mod::factory()->create();
        $other = Mod::factory()->create();

        $this->viewer->ignoreMod($mod);

        expect(IgnoredContent::hiddenModIds($this->viewer))->toBe([$mod->id])
            ->and(IgnoredContent::isHidden($other, $this->viewer))->toBeFalse();
    });

    it('hides mods owned by an ignored user', function (): void {
        $owned = Mod::factory()->create(['owner_id' => $this->author->id]);

        $this->viewer->ignoreUser($this->author);

        expect(IgnoredContent::isHidden($owned, $this->viewer))->toBeTrue();
    });

    it('keeps co-authored mods visible when the ignore covers owned mods only', function (): void {
        $coauthored = Mod::factory()->create();
        $coauthored->additionalAuthors()->attach($this->author);

        $this->viewer->ignoreUser($this->author);

        expect(IgnoredContent::isHidden($coauthored, $this->viewer))->toBeFalse();
    });

    it('hides co-authored mods when the ignore includes them', function (): void {
        $coauthored = Mod::factory()->create();
        $coauthored->additionalAuthors()->attach($this->author);

        $this->viewer->ignoreUser($this->author, includeCoauthored: true);

        expect(IgnoredContent::isHidden($coauthored, $this->viewer))->toBeTrue();
    });

    it('keeps unclaimed mods visible', function (): void {
        $unclaimed = Mod::factory()->create(['owner_id' => null]);

        $this->viewer->ignoreUser($this->author, includeCoauthored: true);

        expect(IgnoredContent::isHidden($unclaimed, $this->viewer))->toBeFalse();
    });

    it("never hides the viewer's own mods", function (): void {
        $shared = Mod::factory()->create(['owner_id' => $this->author->id]);
        $shared->additionalAuthors()->attach($this->viewer);

        $this->viewer->ignoreUser($this->author, includeCoauthored: true);

        expect(IgnoredContent::isHidden($shared, $this->viewer))->toBeFalse();
    });

    it('excludes hidden mods through the query scope', function (): void {
        $ignored = Mod::factory()->create();
        $kept = Mod::factory()->create();

        $this->viewer->ignoreMod($ignored);

        expect(Mod::query()->withoutIgnoredBy($this->viewer)->pluck('id')->all())
            ->toContain($kept->id)
            ->not->toContain($ignored->id);
    });

    it('hides nothing from guests, other users, or under the public viewpoint', function (): void {
        $mod = Mod::factory()->create();
        $this->viewer->ignoreMod($mod);

        expect(IgnoredContent::hiddenModIds(null))->toBe([])
            ->and(IgnoredContent::hiddenModIds(User::factory()->create()))->toBe([])
            ->and(PublicViewpoint::run(fn (): array => IgnoredContent::hiddenModIds($this->viewer)))->toBe([]);
    });

    it('stops hiding a mod once it is unignored', function (): void {
        $mod = Mod::factory()->create();

        $this->viewer->ignoreMod($mod);
        expect(IgnoredContent::isHidden($mod, $this->viewer))->toBeTrue();

        $this->viewer->unignoreMod($mod);
        expect(IgnoredContent::isHidden($mod, $this->viewer))->toBeFalse()
            ->and(IgnoredContent::hasAny($this->viewer))->toBeFalse();
    });
});

describe('hidden addons and lists', function (): void {
    it('hides addons of a hidden mod', function (): void {
        $mod = Mod::factory()->create();
        $addon = Addon::factory()->create(['mod_id' => $mod->id]);

        $this->viewer->ignoreMod($mod);

        expect(IgnoredContent::isHidden($addon, $this->viewer))->toBeTrue();
    });

    it('hides addons owned by an ignored user', function (): void {
        $addon = Addon::factory()->create(['owner_id' => $this->author->id]);

        $this->viewer->ignoreUser($this->author);

        expect(IgnoredContent::isHidden($addon, $this->viewer))->toBeTrue();
    });

    it('keeps an addon without a mod or owner visible', function (): void {
        $addon = Addon::factory()->create(['mod_id' => null, 'owner_id' => null]);

        $this->viewer->ignoreUser($this->author, includeCoauthored: true);

        expect(IgnoredContent::isHidden($addon, $this->viewer))->toBeFalse();
    });

    it('hides lists owned by an ignored user', function (): void {
        $list = ModList::factory()->create(['owner_id' => $this->author->id]);
        $other = ModList::factory()->create();

        $this->viewer->ignoreUser($this->author);

        expect(IgnoredContent::isHidden($list, $this->viewer))->toBeTrue()
            ->and(IgnoredContent::isHidden($other, $this->viewer))->toBeFalse();
    });
});

describe('reasons', function (): void {
    it('names the mod when the mod itself is ignored, even if its owner is too', function (): void {
        $mod = Mod::factory()->create(['owner_id' => $this->author->id]);

        $this->viewer->ignoreUser($this->author);
        $this->viewer->ignoreMod($mod);

        $reason = IgnoredContent::reasonFor($mod, $this->viewer);

        expect($reason?->mod?->id)->toBe($mod->id)
            ->and($reason?->user)->toBeNull();
    });

    it('names the ignored owner', function (): void {
        $mod = Mod::factory()->create(['owner_id' => $this->author->id]);

        $this->viewer->ignoreUser($this->author);

        expect(IgnoredContent::reasonFor($mod, $this->viewer)?->user?->id)->toBe($this->author->id);
    });

    it('names the ignored co-author', function (): void {
        $mod = Mod::factory()->create();
        $mod->additionalAuthors()->attach($this->author);

        $this->viewer->ignoreUser($this->author, includeCoauthored: true);

        expect(IgnoredContent::reasonFor($mod, $this->viewer)?->user?->id)->toBe($this->author->id);
    });

    it("gives addons and issues their mod's reason", function (): void {
        $mod = Mod::factory()->create();
        $addon = Addon::factory()->create(['mod_id' => $mod->id]);
        $issue = ModIssue::factory()->create(['mod_id' => $mod->id]);

        $this->viewer->ignoreMod($mod);

        expect(IgnoredContent::reasonFor($addon, $this->viewer)?->mod?->id)->toBe($mod->id)
            ->and(IgnoredContent::reasonFor($issue, $this->viewer)?->mod?->id)->toBe($mod->id);
    });

    it('returns null for visible content', function (): void {
        expect(IgnoredContent::reasonFor(Mod::factory()->create(), $this->viewer))->toBeNull();
    });
});

describe('users ignoring', function (): void {
    it('lists everyone the mod is hidden from, except its own authors', function (): void {
        $coauthor = User::factory()->create();
        $mod = Mod::factory()->create(['owner_id' => $this->author->id]);
        $mod->additionalAuthors()->attach($coauthor);

        $direct = User::factory()->create();
        $byOwner = User::factory()->create();
        $byCoauthor = User::factory()->create();
        $ownedOnly = User::factory()->create();

        $direct->ignoreMod($mod);
        $byOwner->ignoreUser($this->author);
        $byCoauthor->ignoreUser($coauthor, includeCoauthored: true);
        $ownedOnly->ignoreUser($coauthor);
        $coauthor->ignoreUser($this->author);

        expect(IgnoredContent::usersIgnoring($mod))
            ->toEqualCanonicalizing([$direct->id, $byOwner->id, $byCoauthor->id]);
    });

    it('lists users ignoring the owner of a list', function (): void {
        $list = ModList::factory()->create(['owner_id' => $this->author->id]);
        $this->viewer->ignoreUser($this->author);

        expect(IgnoredContent::usersIgnoring($list))->toBe([$this->viewer->id]);
    });
});
