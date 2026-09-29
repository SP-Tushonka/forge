<?php

declare(strict_types=1);

use App\Models\Mod;
use App\Models\ModUserDownload;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Models\User;

/**
 * @return array{Mod, ModVersion}
 */
function cardFixtureAtVersion(string $versionString): array
{
    SptVersion::factory()->create(['version' => '3.11.4']);
    $mod = Mod::factory()->create(['name' => 'Card Fixture']);
    $version = ModVersion::factory()->recycle($mod)->create(['version' => $versionString, 'spt_version_constraint' => '3.11.4']);

    return [$mod->refresh(), $version];
}

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

describe('Mod Card last download pill', function (): void {
    it('marks a mod the user has on its latest version as downloaded', function (): void {
        [$mod, $version] = cardFixtureAtVersion('2.0.0');
        $user = User::factory()->create();
        ModUserDownload::factory()->forVersion($version)->for($user)->create();

        $this->actingAs($user)
            ->blade('<x-mod.card :mod="$mod" :version="$version" />', ['mod' => $mod, 'version' => $version])
            ->assertSee('Downloaded')
            ->assertSee('You have the latest version')
            ->assertDontSee('Update available');
    });

    it('flags an update when the user downloaded an older version', function (): void {
        [$mod, $version] = cardFixtureAtVersion('2.0.0');
        $user = User::factory()->create();
        ModUserDownload::factory()->for($user)->create(['mod_id' => $mod->id, 'version' => '1.0.0']);

        $this->actingAs($user)
            ->blade('<x-mod.card :mod="$mod" :version="$version" />', ['mod' => $mod, 'version' => $version])
            ->assertSee('Update available')
            ->assertSee('You have v1.0.0, v2.0.0 is out')
            ->assertDontSee('You have the latest version');
    });

    it('shows no pill to guests', function (): void {
        [$mod, $version] = cardFixtureAtVersion('2.0.0');
        ModUserDownload::factory()->forVersion($version)->create();

        $this->blade('<x-mod.card :mod="$mod" :version="$version" />', ['mod' => $mod, 'version' => $version])
            ->assertDontSee('Downloaded')
            ->assertDontSee('Update available');
    });
});
