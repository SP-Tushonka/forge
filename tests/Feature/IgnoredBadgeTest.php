<?php

declare(strict_types=1);

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;

beforeEach(function (): void {
    $this->withoutDefer();
    SptVersion::factory()->create(['version' => '1.0.0']);
});

it('tags an ignored dependency on the mod page instead of hiding it', function (): void {
    $dependency = Mod::factory()->create(['name' => 'Required Library', 'published_at' => now()->subDay()]);
    ModVersion::factory()->recycle($dependency)->create(['spt_version_constraint' => '1.0.0', 'version' => '1.0.0']);

    $mod = Mod::factory()->create(['published_at' => now()->subDay()]);
    $version = ModVersion::factory()->for($mod)->create([
        'spt_version_constraint' => '1.0.0',
        'disabled' => false,
        'published_at' => now()->subDay(),
    ]);
    Dependency::factory()->forModVersion($version)->create(['dependent_mod_id' => $dependency->id]);

    $viewer = User::factory()->create();
    $viewer->ignoreMod($dependency);

    $this->actingAs($viewer)->get($mod->detail_url)
        ->assertSee('Required Library')
        ->assertSee('data-test="ignored-badge"', false);

    $this->actingAs(User::factory()->create())->get($mod->detail_url)
        ->assertDontSee('data-test="ignored-badge"', false);
});
