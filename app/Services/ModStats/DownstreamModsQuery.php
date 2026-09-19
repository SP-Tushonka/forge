<?php

declare(strict_types=1);

namespace App\Services\ModStats;

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use Illuminate\Support\Facades\DB;

/**
 * Finds the mods that currently depend on a mod: publicly visible mods whose latest publicly visible version declares
 * the dependency. Global scopes are bypassed and visibility applied explicitly, so an admin or owner viewing the stats
 * never sees someone else's unpublished mod listed.
 */
final class DownstreamModsQuery
{
    /**
     * @return list<int>
     */
    public function dependentModIds(Mod $mod): array
    {
        $dependingVersionIds = Dependency::query()
            ->where('dependable_type', (new ModVersion)->getMorphClass())
            ->where('dependent_mod_id', $mod->id)
            ->get(['dependable_id'])
            ->map(fn (Dependency $dependency): int => $dependency->dependable_id)
            ->all();

        if ($dependingVersionIds === []) {
            return [];
        }

        $candidateModIds = DB::table('mod_versions')
            ->whereIn('id', $dependingVersionIds)
            ->where('mod_id', '!=', $mod->id)
            ->distinct()
            ->pluck('mod_id')
            ->all();

        // Same ordering as Mod::versions(), so "latest" means what the rest of the site means by it.
        $latestVersions = ModVersion::query()
            ->withoutGlobalScopes()
            ->publiclyVisible()
            ->whereIn('mod_id', $candidateModIds)
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor')
            ->orderByDesc('version_patch')
            ->orderByRaw('CASE WHEN version_labels = ? THEN 0 ELSE 1 END', [''])
            ->orderBy('version_labels')
            ->get(['id', 'mod_id'])
            ->unique('mod_id');

        $dependentModIds = $latestVersions
            ->filter(fn (ModVersion $version): bool => in_array($version->id, $dependingVersionIds, true))
            ->map(fn (ModVersion $version): int => $version->mod_id)
            ->all();

        return array_values(Mod::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $dependentModIds)
            ->where('disabled', false)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('id')
            ->get(['id'])
            ->map(fn (Mod $dependent): int => $dependent->id)
            ->all());
    }
}
