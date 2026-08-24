<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;

describe('Mod Card Blade Component', function (): void {
    it('renders the endorsement figure with a formatted count and pluralized title', function (int $count, string $title): void {
        SptVersion::factory()->create(['version' => '3.11.4']);
        $mod = Mod::factory()->create(['name' => 'Card Fixture']);
        $version = ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '3.11.4']);
        $mod->refresh();

        $this->blade(
            '<x-mod.card :mod="$mod" :version="$version" :endorsements-count="$count" />',
            ['mod' => $mod, 'version' => $version, 'count' => $count],
        )->assertSee($title);
    })->with([
        [1234, '1,234 Endorsements'],
        [1, '1 Endorsement'],
        [0, '0 Endorsements'],
    ]);

    it('renders no endorsement figure when the count is omitted', function (): void {
        SptVersion::factory()->create(['version' => '3.11.4']);
        $mod = Mod::factory()->create(['name' => 'Card Fixture']);
        $version = ModVersion::factory()->recycle($mod)->create(['spt_version_constraint' => '3.11.4']);
        $mod->refresh();

        $this->blade(
            '<x-mod.card :mod="$mod" :version="$version" />',
            ['mod' => $mod, 'version' => $version],
        )->assertDontSee('Endorsement');
    });
});
