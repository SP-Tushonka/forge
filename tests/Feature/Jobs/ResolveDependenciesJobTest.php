<?php

declare(strict_types=1);

use App\Jobs\ResolveDependenciesJob;
use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Services\DependencyVersionService;
use Illuminate\Support\Facades\DB;

/**
 * A mod version that depends on $dependsOn with the given constraint.
 */
function versionDependingOn(Mod $dependsOn, string $constraint = '^1.0.0'): ModVersion
{
    $version = ModVersion::factory()->create();

    Dependency::factory()->create([
        'dependable_id' => $version->id,
        'dependable_type' => ModVersion::class,
        'dependent_mod_id' => $dependsOn->id,
        'constraint' => $constraint,
    ]);

    return $version;
}

function runResolveJob(): void
{
    new ResolveDependenciesJob()->handle(resolve(DependencyVersionService::class));
}

it('resolves only the versions that satisfy the constraint', function (): void {
    $dependency = Mod::factory()->create();
    $matching = ModVersion::factory()->for($dependency)->create(['version' => '1.4.0']);
    ModVersion::factory()->for($dependency)->create(['version' => '2.0.0']);

    $version = versionDependingOn($dependency);

    runResolveJob();

    expect($version->dependenciesResolved()->pluck('mod_versions.id')->all())
        ->toEqualCanonicalizing([$matching->id]);
});

it('detaches resolved rows that no longer satisfy the constraint', function (): void {
    $dependency = Mod::factory()->create();
    $matching = ModVersion::factory()->for($dependency)->create(['version' => '1.4.0']);
    $outOfRange = ModVersion::factory()->for($dependency)->create(['version' => '2.0.0']);

    $version = versionDependingOn($dependency);
    runResolveJob();

    $version->dependencies()->update(['constraint' => '^2.0.0']);
    runResolveJob();

    $version->refresh();

    expect($version->dependenciesResolved()->pluck('mod_versions.id')->all())
        ->toEqualCanonicalizing([$outOfRange->id])
        ->and($matching->exists)->toBeTrue();
});

it('never touches the pivot for versions without dependencies', function (): void {
    ModVersion::factory()->count(5)->create();

    DB::enableQueryLog();
    runResolveJob();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (mixed $q): bool => is_string($q) && str_contains($q, 'dependencies_resolved')))->toBeEmpty()
        ->and(DB::table('dependencies_resolved')->count())->toBe(0);
});

it('ignores unpublished versions of the dependent mod', function (): void {
    $dependency = Mod::factory()->create();
    $published = ModVersion::factory()->for($dependency)->create(['version' => '1.4.0']);
    ModVersion::factory()->for($dependency)->create(['version' => '1.9.0', 'published_at' => null]);
    ModVersion::factory()->for($dependency)->create(['version' => '1.8.0', 'published_at' => now()->addWeek()]);

    $version = versionDependingOn($dependency);

    runResolveJob();

    expect($version->dependenciesResolved()->withoutGlobalScopes()->pluck('mod_versions.id')->all())
        ->toEqualCanonicalizing([$published->id]);
});

it('writes nothing on a second sweep when the pivot already matches', function (): void {
    $dependency = Mod::factory()->create();
    ModVersion::factory()->for($dependency)->create(['version' => '1.4.0']);
    ModVersion::factory()->for($dependency)->create(['version' => '1.6.0']);

    versionDependingOn($dependency);
    runResolveJob();

    DB::enableQueryLog();
    runResolveJob();
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $writes = $queries->filter(fn (mixed $q): bool => is_string($q)
        && preg_match('/^(insert|update|delete)/i', mb_ltrim($q)) === 1);

    expect($writes)->toBeEmpty();
});

it('repairs a pivot that drifted into a duplicate and a missing row', function (): void {
    $dependency = Mod::factory()->create();
    $first = ModVersion::factory()->for($dependency)->create(['version' => '1.4.0']);
    $second = ModVersion::factory()->for($dependency)->create(['version' => '1.6.0']);

    $version = versionDependingOn($dependency);
    runResolveJob();

    $dependencyRow = Dependency::query()->where('dependable_id', $version->id)->firstOrFail();

    DB::table('dependencies_resolved')
        ->where('dependable_id', $version->id)
        ->where('resolved_mod_version_id', $second->id)
        ->delete();

    DB::table('dependencies_resolved')->insert([
        'dependable_id' => $version->id,
        'dependable_type' => ModVersion::class,
        'dependency_id' => $dependencyRow->id,
        'resolved_mod_version_id' => $first->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runResolveJob();

    expect($version->dependenciesResolved()->pluck('mod_versions.id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->and(DB::table('dependencies_resolved')->where('dependable_id', $version->id)->count())->toBe(2);
});

it('rewrites a row whose dependency_id no longer owns the resolution', function (): void {
    $dependency = Mod::factory()->create();
    $resolved = ModVersion::factory()->for($dependency)->create(['version' => '1.4.0']);

    $other = Mod::factory()->create();
    ModVersion::factory()->for($other)->create(['version' => '1.1.0']);

    $version = versionDependingOn($dependency);
    Dependency::factory()->create([
        'dependable_id' => $version->id,
        'dependable_type' => ModVersion::class,
        'dependent_mod_id' => $other->id,
        'constraint' => '^1.0.0',
    ]);
    runResolveJob();

    $wrongOwner = Dependency::query()->where('dependable_id', $version->id)
        ->where('dependent_mod_id', $other->id)
        ->firstOrFail();

    $correctOwner = Dependency::query()->where('dependable_id', $version->id)
        ->where('dependent_mod_id', $dependency->id)
        ->firstOrFail();

    DB::table('dependencies_resolved')
        ->where('dependable_id', $version->id)
        ->where('resolved_mod_version_id', $resolved->id)
        ->update(['dependency_id' => $wrongOwner->id]);

    runResolveJob();

    expect(DB::table('dependencies_resolved')
        ->where('dependable_id', $version->id)
        ->where('resolved_mod_version_id', $resolved->id)
        ->value('dependency_id'))->toBe($correctOwner->id);
});

it('resolves every eligible version across chunk boundaries', function (): void {
    $dependency = Mod::factory()->create();
    $matching = ModVersion::factory()->for($dependency)->create(['version' => '1.1.0']);

    $versions = collect(range(1, 12))->map(fn (): ModVersion => versionDependingOn($dependency));

    runResolveJob();

    foreach ($versions as $version) {
        expect($version->dependenciesResolved()->pluck('mod_versions.id')->all())
            ->toEqualCanonicalizing([$matching->id]);
    }
});
