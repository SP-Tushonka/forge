<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ModUserDownload;
use App\Models\ModVersion;

/**
 * The signed-in user's latest download of each mod, read once per request so any number of mod cards cost one query.
 */
final class LastDownloads
{
    /**
     * The signed-in user's latest download of the mod, if they have one.
     */
    public static function forMod(int $modId): ?ModUserDownload
    {
        return self::forCurrentUser()[$modId] ?? null;
    }

    /**
     * Whether the version on offer is newer than the one the user last downloaded.
     */
    public static function updateAvailable(?ModUserDownload $download, ?ModVersion $offered): bool
    {
        return $download instanceof ModUserDownload
            && $offered instanceof ModVersion
            && VersionMatcher::isNewer($offered->version, $download->version);
    }

    /**
     * @return array<int, ModUserDownload>
     */
    private static function forCurrentUser(): array
    {
        $userId = auth()->id();

        if ($userId === null) {
            return [];
        }

        return once(fn (): array => ModUserDownload::query()
            ->where('user_id', $userId)
            ->get(['mod_id', 'version', 'downloaded_at'])
            ->mapWithKeys(fn (ModUserDownload $download): array => [$download->mod_id => $download])
            ->all());
    }
}
