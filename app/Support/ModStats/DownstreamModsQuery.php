<?php

declare(strict_types=1);

namespace App\Support\ModStats;

use App\Models\Dependency;
use App\Models\Mod;
use App\Models\ModVersion;
use App\Models\ModVersionSptVersion;
use App\Models\SptVersion;
use Illuminate\Database\Eloquent\Collection;
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
        [$versions, $depending] = $this->candidateVersions($mod);

        return array_keys($this->keepDepending($this->newestPerMod($versions), $depending));
    }

    /**
     * Maps each dependent mod's id to the version that makes it a dependent: overall ("latest") its newest publicly
     * visible version, and per SPT version id ("bySpt") its newest publicly visible version supporting that SPT
     * version, provided that version declares the dependency. SPT versions without a dependent are left out.
     *
     * @return array{latest: array<int, int>, bySpt: array<int, array<int, int>>}
     */
    public function dependentVersionIds(Mod $mod): array
    {
        [$versions, $depending] = $this->candidateVersions($mod);

        $sptLinks = ModVersionSptVersion::query()
            ->whereIn('mod_version_id', $versions->modelKeys())
            ->whereIn('spt_version_id', SptVersion::query()->select('id'))
            ->get(['mod_version_id', 'spt_version_id'])
            ->groupBy('mod_version_id');

        $newestBySpt = [];
        foreach ($versions as $version) {
            foreach ($sptLinks->get($version->id) ?? [] as $link) {
                $newestBySpt[$link->spt_version_id][$version->mod_id] ??= $version->id;
            }
        }

        return [
            'latest' => $this->keepDepending($this->newestPerMod($versions), $depending),
            'bySpt' => array_filter(array_map(
                fn (array $newest): array => $this->keepDepending($newest, $depending),
                $newestBySpt,
            )),
        ];
    }

    /**
     * The publicly visible versions of the visible mods that have ever declared the dependency, each mod's newest
     * first, along with the ids of the versions declaring it.
     *
     * @return array{Collection<int, ModVersion>, array<int, true>}
     */
    private function candidateVersions(Mod $mod): array
    {
        $dependingVersionIds = Dependency::query()
            ->where('dependable_type', (new ModVersion)->getMorphClass())
            ->where('dependent_mod_id', $mod->id)
            ->get(['dependable_id'])
            ->map(fn (Dependency $dependency): int => $dependency->dependable_id)
            ->all();

        if ($dependingVersionIds === []) {
            return [new Collection, []];
        }

        $candidateModIds = DB::table('mod_versions')
            ->whereIn('id', $dependingVersionIds)
            ->where('mod_id', '!=', $mod->id)
            ->distinct()
            ->pluck('mod_id')
            ->all();

        $visibleModIds = Mod::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $candidateModIds)
            ->where('disabled', false)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->select('id');

        // Same ordering as Mod::versions(), so "latest" means what the rest of the site means by it.
        $versions = ModVersion::query()
            ->withoutGlobalScopes()
            ->publiclyVisible()
            ->whereIn('mod_id', $visibleModIds)
            ->orderByDesc('version_major')
            ->orderByDesc('version_minor')
            ->orderByDesc('version_patch')
            ->orderByRaw('CASE WHEN version_labels = ? THEN 0 ELSE 1 END', [''])
            ->orderBy('version_labels')
            ->get(['id', 'mod_id']);

        return [$versions, array_fill_keys($dependingVersionIds, true)];
    }

    /**
     * @param  Collection<int, ModVersion>  $versions
     * @return array<int, int>
     */
    private function newestPerMod(Collection $versions): array
    {
        $newest = [];
        foreach ($versions as $version) {
            $newest[$version->mod_id] ??= $version->id;
        }

        return $newest;
    }

    /**
     * @param  array<int, int>  $versionIds
     * @param  array<int, true>  $depending
     * @return array<int, int>
     */
    private function keepDepending(array $versionIds, array $depending): array
    {
        $kept = array_filter($versionIds, fn (int $versionId): bool => isset($depending[$versionId]));
        ksort($kept);

        return $kept;
    }
}
