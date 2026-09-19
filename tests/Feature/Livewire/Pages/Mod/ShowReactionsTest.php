<?php

declare(strict_types=1);

use App\Models\Emoji;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutDefer();

    SptVersion::factory()->create(['version' => '1.0.0']);

    $this->mod = Mod::factory()->create(['disabled' => false, 'published_at' => now()->subHour()]);
    ModVersion::factory()->recycle($this->mod)->create([
        'version' => '1.0.0',
        'spt_version_constraint' => '1.0.0',
    ]);

    $this->heart = Emoji::query()->where('shortcode', 'heart')->sole();
});

/**
 * The detail page URL for the mod under test.
 */
$url = fn (Mod $mod): string => route('mod.show', ['modId' => $mod->id, 'slug' => $mod->slug]);

describe('mod detail page reactions', function () use ($url): void {
    it('renders the reaction bar', function () use ($url): void {
        $this->actingAs(User::factory()->create())
            ->get($url($this->mod))
            ->assertOk()
            ->assertSee('reaction-bar-mod-'.$this->mod->id, false);
    });

    it('renders a chip for an emoji that has been used', function () use ($url): void {
        $this->mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => $this->heart->id,
        ]);

        $this->actingAs(User::factory()->create())
            ->get($url($this->mod))
            ->assertOk()
            ->assertSee('reaction-chip-'.$this->mod->id.'-heart', false);
    });

    it('offers the picker to an eligible viewer', function () use ($url): void {
        $this->actingAs(User::factory()->create())
            ->get($url($this->mod))
            ->assertOk()
            ->assertSee('reaction-add-'.$this->mod->id, false);
    });

    it('does not offer the picker to a guest', function () use ($url): void {
        $this->mod->reactions()->create([
            'user_id' => User::factory()->create()->id,
            'emoji_id' => $this->heart->id,
        ]);

        $this->get($url($this->mod))
            ->assertOk()
            ->assertSee('reaction-chip-'.$this->mod->id.'-heart', false)
            ->assertDontSee('reaction-add-'.$this->mod->id, false);
    });

    it('does not offer the picker to the mod owner', function () use ($url): void {
        $owner = User::factory()->create();
        $this->mod->owner()->associate($owner)->save();

        $this->actingAs($owner)
            ->get($url($this->mod))
            ->assertOk()
            ->assertDontSee('reaction-add-'.$this->mod->id, false);
    });
});
