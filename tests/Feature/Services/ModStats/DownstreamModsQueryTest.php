<?php

declare(strict_types=1);

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\SptVersion;
use App\Services\ModStats\DownstreamModsQuery;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    SptVersion::query()->firstOrCreate(['version' => '1.0.0'], SptVersion::factory()->make(['version' => '1.0.0'])->toArray());
    $this->library = Mod::factory()->create();
    $this->query = new DownstreamModsQuery;
    $this->release = function (Mod $mod, string $version, bool $dependsOnLibrary, bool $disabled = false): ModVersion {
        [$major, $minor, $patch] = array_map(intval(...), explode('.', $version));
        $modVersion = ModVersion::factory()->for($mod)->create([
            'version' => $version,
            'version_major' => $major,
            'version_minor' => $minor,
            'version_patch' => $patch,
            'version_labels' => '',
            'spt_version_constraint' => '1.0.0',
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
