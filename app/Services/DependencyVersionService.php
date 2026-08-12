<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DependencyResolver;
use App\Models\AddonVersion;
use App\Models\ModVersion;
use App\Support\VersionMatcher;
use Illuminate\Database\Query\Builder;

final class DependencyVersionService implements DependencyResolver
{
    /**
     * Resolve the dependencies for a mod version or addon version.
     */
    public function resolve(ModVersion|AddonVersion $dependable): void
    {
        // Refresh the dependencies relationship to get the latest state
        $dependable->load('dependencies');

        $this->reconcilePivots($dependable, $this->satisfyConstraint($dependable));
    }

    /**
     * Reconcile the depndencies pivot with the desired state, writing only the difference. sync() cannot be
     * used here: it re UPDATEs every already attached row whenever pivot attributes are passed, which on a full sweep
     * its thousands of writes setting dependency_id to the value it already holds for no reason.
     *
     * @param  array<int, array<string, int>>  $dependencies  dependency_id keyed by resolved mod version ID.
     */
    private function reconcilePivots(ModVersion|AddonVersion $dependable, array $dependencies): void
    {
        $type = $dependable::class;

        $pivot = fn (): Builder => $dependable->dependenciesResolved()
            ->newPivotStatement()
            ->where('dependable_id', $dependable->getKey())
            ->where('dependable_type', $type);

        $storedByResolved = [];
        foreach ($pivot()->get(['resolved_mod_version_id', 'dependency_id']) as $row) {
            /** @var object{resolved_mod_version_id: int, dependency_id: int} $row */
            $storedByResolved[$row->resolved_mod_version_id][] = $row->dependency_id;
        }

        $stale = [];
        foreach ($storedByResolved as $resolvedId => $dependencyIds) {
            if (count($dependencyIds) !== 1 || ($dependencies[$resolvedId]['dependency_id'] ?? null) !== $dependencyIds[0]) {
                $stale[] = $resolvedId;
            }
        }

        $now = now();
        $inserts = [];
        foreach ($dependencies as $resolvedId => $attributes) {
            if (isset($storedByResolved[$resolvedId]) && ! in_array($resolvedId, $stale, true)) {
                continue;
            }

            $inserts[] = [
                'dependable_id' => $dependable->getKey(),
                'dependable_type' => $type,
                'dependency_id' => $attributes['dependency_id'],
                'resolved_mod_version_id' => $resolvedId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($stale !== []) {
            $pivot()->whereIn('resolved_mod_version_id', $stale)->delete();
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            $dependable->dependenciesResolved()->newPivotStatement()->insertOrIgnore($chunk);
        }
    }

    /**
     * Satisfies all dependency constraints of a ModVersion or AddonVersion.
     *
     * @return array<int, array<string, int>>
     */
    private function satisfyConstraint(ModVersion|AddonVersion $dependable): array
    {
        $dependencies = [];
        foreach ($dependable->dependencies as $dependency) {
            // Skip if the dependency is being deleted or doesn't exist
            if (! $dependency->exists) {
                continue;
            }

            if (! $dependency->id) {
                continue;
            }

            $dependentModVersions = ModVersion::withoutGlobalScopes()
                ->where('mod_id', $dependency->dependent_mod_id)
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->select(['id', 'version'])
                ->get();

            // Filter the dependent mod versions to find the ones that satisfy the dependency constraint.
            $matchedVersions = $dependentModVersions->filter(fn (ModVersion $version): bool => VersionMatcher::satisfies($version->version, $dependency->constraint));

            // Map the matched versions to the sync data.
            foreach ($matchedVersions as $matchedVersion) {
                $dependencies[$matchedVersion->id] = ['dependency_id' => $dependency->id];
            }
        }

        return $dependencies;
    }
}
