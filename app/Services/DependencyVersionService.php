<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\DependencyResolver;
use App\Models\AddonVersion;
use App\Models\ModVersion;
use App\Support\VersionMatcher;

final class DependencyVersionService implements DependencyResolver
{
    /**
     * Resolve the dependencies for a mod version or addon version.
     */
    public function resolve(ModVersion|AddonVersion $dependable): void
    {
        // Refresh the dependencies relationship to get the latest state
        $dependable->load('dependencies');

        $dependencies = $this->satisfyConstraint($dependable);
        $dependable->dependenciesResolved()->sync($dependencies);
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
