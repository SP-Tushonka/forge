<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Addon;
use App\Models\IgnoredMod;
use App\Models\IgnoredUser;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\ModList;
use App\Models\User;
use App\Support\Api\V0\PublicViewpoint;
use App\Support\DataTransferObjects\IgnoreReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Once;

/**
 * What a viewer has chosen to ignore, resolved to the mod, addon and list ids hidden from them. Every set is read once
 * per request. A viewer's own mods, addons and lists are never hidden.
 */
final class IgnoredContent
{
    public static function hasAny(?User $viewer): bool
    {
        return self::sets($viewer) !== null;
    }

    /**
     * @return list<int>
     */
    public static function hiddenModIds(?User $viewer): array
    {
        $sets = self::sets($viewer);

        if ($viewer === null || $sets === null) {
            return [];
        }

        $viewerId = $viewer->id;

        return once(fn (): array => self::toIntList(Mod::query()
            ->withoutGlobalScopes()
            ->where(function (Builder $hidden) use ($sets): void {
                $hidden->whereIn('mods.id', $sets['mods']);
                self::orWhereAuthoredByIgnored($hidden, 'mods', new Mod, $sets);
            })
            ->where(fn (Builder $notOwn) => self::whereNotAuthoredBy($notOwn, 'mods', new Mod, $viewerId))
            ->pluck('mods.id')));
    }

    /**
     * @return list<int>
     */
    public static function hiddenAddonIds(?User $viewer): array
    {
        $sets = self::sets($viewer);

        if ($viewer === null || $sets === null) {
            return [];
        }

        $viewerId = $viewer->id;
        $hiddenModIds = self::hiddenModIds($viewer);

        return once(fn (): array => self::toIntList(Addon::query()
            ->withoutGlobalScopes()
            ->where(function (Builder $hidden) use ($sets, $hiddenModIds): void {
                $hidden->whereIn('addons.mod_id', $hiddenModIds);
                self::orWhereAuthoredByIgnored($hidden, 'addons', new Addon, $sets);
            })
            ->where(fn (Builder $notOwn) => self::whereNotAuthoredBy($notOwn, 'addons', new Addon, $viewerId))
            ->pluck('addons.id')));
    }

    /**
     * @return list<int>
     */
    public static function hiddenListIds(?User $viewer): array
    {
        $sets = self::sets($viewer);

        if ($sets === null || $sets['users'] === []) {
            return [];
        }

        return once(fn (): array => self::toIntList(ModList::query()
            ->withoutGlobalScopes()
            ->whereIn('owner_id', $sets['users'])
            ->pluck('id')));
    }

    public static function isHidden(Mod|Addon|ModList $model, ?User $viewer): bool
    {
        $hidden = match (true) {
            $model instanceof Mod => self::hiddenModIds($viewer),
            $model instanceof Addon => self::hiddenAddonIds($viewer),
            $model instanceof ModList => self::hiddenListIds($viewer),
        };

        return in_array($model->id, $hidden, true);
    }

    /**
     * Why the model is hidden from the viewer, or null when it isn't. An ignored mod outranks an ignored author, and
     * addons and issues inherit their mod's reason.
     */
    public static function reasonFor(Mod|Addon|ModList|ModIssue $model, ?User $viewer): ?IgnoreReason
    {
        if ($model instanceof ModIssue) {
            $mod = Mod::query()->withoutGlobalScopes()->find($model->mod_id);

            return $mod instanceof Mod ? self::reasonFor($mod, $viewer) : null;
        }

        $sets = self::sets($viewer);

        if ($sets === null || ! self::isHidden($model, $viewer)) {
            return null;
        }

        if ($model instanceof Mod && in_array($model->id, $sets['mods'], true)) {
            return new IgnoreReason(mod: $model);
        }

        if ($model instanceof Addon && $model->mod_id !== null && in_array($model->mod_id, self::hiddenModIds($viewer), true)) {
            $mod = Mod::query()->withoutGlobalScopes()->find($model->mod_id);

            return $mod instanceof Mod ? self::reasonFor($mod, $viewer) : null;
        }

        $author = self::ignoredAuthorOf($model, $sets);

        return $author instanceof User ? new IgnoreReason(user: $author) : null;
    }

    /**
     * The ids of every user the model is hidden from, for work that runs without a viewer, such as notifications.
     *
     * @return list<int>
     */
    public static function usersIgnoring(Model $model): array
    {
        if ($model instanceof ModIssue) {
            $mod = Mod::query()->withoutGlobalScopes()->find($model->mod_id);

            return $mod instanceof Mod ? self::usersIgnoring($mod) : [];
        }

        if ($model instanceof ModList) {
            return self::toIntList(IgnoredUser::query()->where('ignored_user_id', $model->owner_id)->pluck('user_id'));
        }

        if (! $model instanceof Mod && ! $model instanceof Addon) {
            return [];
        }

        $ignoring = [];

        if ($model instanceof Mod) {
            $ignoring = self::toIntList(IgnoredMod::query()->where('mod_id', $model->id)->pluck('user_id'));
        } elseif ($model->mod_id !== null) {
            $mod = Mod::query()->withoutGlobalScopes()->find($model->mod_id);
            $ignoring = $mod instanceof Mod ? self::usersIgnoring($mod) : [];
        }

        $authorIds = self::toIntList($model->additionalAuthors()->pluck('users.id'));

        $byAuthor = IgnoredUser::query()
            ->where(function (Builder $query) use ($model, $authorIds): void {
                $query->whereIn('ignored_user_id', array_filter([$model->owner_id]))
                    ->orWhere(fn (Builder $coauthor) => $coauthor->where('include_coauthored', true)->whereIn('ignored_user_id', $authorIds));
            })
            ->pluck('user_id');

        $own = array_filter([$model->owner_id, ...$authorIds]);

        return array_values(array_diff(array_unique([...$ignoring, ...self::toIntList($byAuthor)]), $own));
    }

    /**
     * Forget the per-request sets after the viewer's ignores change.
     */
    public static function flush(): void
    {
        Once::flush();
    }

    /**
     * @return array{mods: list<int>, users: list<int>, coauthors: list<int>}|null
     */
    private static function sets(?User $viewer): ?array
    {
        // Shared caches are filled under the public viewpoint and must never carry one viewer's ignores.
        if ($viewer === null || PublicViewpoint::isForced()) {
            return null;
        }

        $viewerId = $viewer->id;

        $sets = once(function () use ($viewerId): array {
            $users = IgnoredUser::query()->where('user_id', $viewerId)->get(['ignored_user_id', 'include_coauthored']);

            return [
                'mods' => self::toIntList(IgnoredMod::query()->where('user_id', $viewerId)->pluck('mod_id')),
                'users' => self::toIntList($users->pluck('ignored_user_id')),
                'coauthors' => self::toIntList($users->where('include_coauthored', true)->pluck('ignored_user_id')),
            ];
        });

        return $sets['mods'] === [] && $sets['users'] === [] ? null : $sets;
    }

    /**
     * Owned by an ignored user, or co-authored by one whose ignore includes co-authored content.
     *
     * @param  Builder<covariant Model>  $query
     * @param  array{mods: list<int>, users: list<int>, coauthors: list<int>}  $sets
     */
    private static function orWhereAuthoredByIgnored(Builder $query, string $table, Model $model, array $sets): void
    {
        $query->orWhereIn($table.'.owner_id', $sets['users']);

        if ($sets['coauthors'] !== []) {
            $query->orWhereIn($table.'.id', fn (QueryBuilder $authors): QueryBuilder => self::coauthoredIds($authors, $model)
                ->whereIn('user_id', $sets['coauthors']));
        }
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private static function whereNotAuthoredBy(Builder $query, string $table, Model $model, int $userId): void
    {
        $query->where(fn (Builder $owner) => $owner->whereNull($table.'.owner_id')->orWhere($table.'.owner_id', '!=', $userId))
            ->whereNotIn($table.'.id', fn (QueryBuilder $authors): QueryBuilder => self::coauthoredIds($authors, $model)
                ->where('user_id', $userId));
    }

    /**
     * An uncorrelated id list rather than a correlated EXISTS, so the database filters additional_authors by its
     * user_id index once instead of probing it for every mod or addon row.
     */
    private static function coauthoredIds(QueryBuilder $query, Model $model): QueryBuilder
    {
        return $query->select('authorable_id')
            ->from('additional_authors')
            ->where('authorable_type', $model->getMorphClass());
    }

    /**
     * @param  array{mods: list<int>, users: list<int>, coauthors: list<int>}  $sets
     */
    private static function ignoredAuthorOf(Mod|Addon|ModList $model, array $sets): ?User
    {
        if (in_array($model->owner_id, $sets['users'], true)) {
            return User::query()->find($model->owner_id);
        }

        if ($model instanceof ModList || $sets['coauthors'] === []) {
            return null;
        }

        return $model->additionalAuthors()->whereIn('users.id', $sets['coauthors'])->first();
    }

    /**
     * @param  iterable<array-key, mixed>  $ids
     * @return list<int>
     */
    private static function toIntList(iterable $ids): array
    {
        $list = [];

        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $list[] = (int) $id;
            }
        }

        return array_values(array_unique($list));
    }
}
