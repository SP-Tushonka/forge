<?php

declare(strict_types=1);

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Support\ModStats\DownstreamModsQuery;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());
    $this->library = Mod::factory()->create();
    $this->query = new DownstreamModsQuery;
    $this->release = function (Mod $mod, string $version, bool $dependsOnLibrary, bool $disabled = false, string $spt = '1.0.0'): ModVersion {
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $version));
        $modVersion = ModVersion::factory()->for($mod)->create([
            'version' => $version,
            'version_major' => $major,
            'version_minor' => $minor,
            'version_patch' => $patch,
            'version_labels' => '',
            'spt_version_constraint' => $spt,
            'disabled' => $disabled,
            'published_at' => now()->subDay(),
        ]);

        if ($dependsOnLibrary) {
            Dependency::factory()->forModVersion($modVersion)->create(['dependent_mod_id' => $this->library->id]);
        }

        return $modVersion;
    };
});

it('includes a mod whose latest version depends on the library', function (): void {
    $dependent = Mod::factory()->create();
    ($this->release)($dependent, '1.0.0', false);
    ($this->release)($dependent, '1.1.0', true);

    expect($this->query->dependentModIds($this->library))->toBe([$dependent->id]);
});

it('excludes a mod that dropped the dependency in its latest version', function (): void {
    $former = Mod::factory()->create();
    ($this->release)($former, '1.0.0', true);
    ($this->release)($former, '2.0.0', false);

    expect($this->query->dependentModIds($this->library))->toBe([]);
});

it('uses the latest publicly visible version, skipping disabled ones', function (): void {
    $dependent = Mod::factory()->create();
    ($this->release)($dependent, '1.0.0', true);
    ($this->release)($dependent, '2.0.0', false, disabled: true);

    expect($this->query->dependentModIds($this->library))->toBe([$dependent->id]);
});

it('excludes unpublished and disabled dependent mods', function (): void {
    ($this->release)(Mod::factory()->unpublished()->create(), '1.0.0', true);
    ($this->release)(Mod::factory()->disabled()->create(), '1.0.0', true);

    expect($this->query->dependentModIds($this->library))->toBe([]);
});

describe('per SPT version', function (): void {
    beforeEach(function (): void {
        $this->spt3 = SptVersion::factory()->create(['version' => '3.11.4']);
        $this->spt4 = SptVersion::factory()->create(['version' => '4.0.0']);
    });

    it('maps each dependent to its newest version, overall and per SPT version', function (): void {
        $dependent = Mod::factory()->create();
        ($this->release)($dependent, '1.0.0', true, spt: '3.11.4');
        $forSpt3 = ($this->release)($dependent, '1.1.0', true, spt: '3.11.4');
        $forSpt4 = ($this->release)($dependent, '2.0.0', true, spt: '4.0.0');

        $map = $this->query->dependentVersionIds($this->library);

        expect($map['latest'])->toBe([$dependent->id => $forSpt4->id])
            ->and($map['bySpt'])->toHaveCount(2)
            ->and($map['bySpt'][$this->spt3->id])->toBe([$dependent->id => $forSpt3->id])
            ->and($map['bySpt'][$this->spt4->id])->toBe([$dependent->id => $forSpt4->id]);
    });

    it('keeps a mod under an older SPT version after it dropped the dependency for a newer one', function (): void {
        $former = Mod::factory()->create();
        $old = ($this->release)($former, '1.0.0', true, spt: '3.11.4');
        ($this->release)($former, '2.0.0', false, spt: '4.0.0');

        expect($this->query->dependentVersionIds($this->library))->toBe([
            'latest' => [],
            'bySpt' => [$this->spt3->id => [$former->id => $old->id]],
        ])->and($this->query->dependentModIds($this->library))->toBe([]);
    });

    it('leaves out an SPT version when each mod newest version for it dropped the dependency', function (): void {
        $mod = Mod::factory()->create();
        ($this->release)($mod, '1.0.0', true, spt: '3.11.4');
        ($this->release)($mod, '1.1.0', false, spt: '3.11.4');

        expect($this->query->dependentVersionIds($this->library)['bySpt'])->toBe([]);
    });

    it('ignores disabled versions, hidden mods and unpublished SPT versions', function (): void {
        $unpublishedSpt = SptVersion::factory()->unpublished()->create(['version' => '4.1.0']);
        ($this->release)(Mod::factory()->create(), '1.0.0', true, disabled: true, spt: '3.11.4');
        ($this->release)(Mod::factory()->unpublished()->create(), '1.0.0', true, spt: '3.11.4');
        ($this->release)(Mod::factory()->disabled()->create(), '1.0.0', true, spt: '3.11.4');
        $visible = ($this->release)(Mod::factory()->create(), '1.0.0', true, spt: '4.0.0');
        $visible->sptVersions()->attach($unpublishedSpt->id);

        expect(array_keys($this->query->dependentVersionIds($this->library)['bySpt']))->toBe([$this->spt4->id]);
    });
});
