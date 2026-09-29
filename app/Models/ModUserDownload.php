<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ModUserDownloadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * The version of a mod a user downloaded most recently: one row per user per mod.
 *
 * @property int $id
 * @property int $user_id
 * @property int $mod_id
 * @property int|null $mod_version_id
 * @property string $version
 * @property CarbonImmutable $downloaded_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Mod $mod
 * @property-read ModVersion|null $modVersion
 */
final class ModUserDownload extends Model
{
    /** @use HasFactory<ModUserDownloadFactory> */
    use HasFactory;

    /**
     * Record that the user has just downloaded this version, replacing their previous record for the mod.
     */
    public static function record(User $user, ModVersion $version): void
    {
        // Staff and authors can fetch hidden versions; recording those would displace the version the user actually runs.
        // Read past the viewer-dependent published scope: visibility here means visibility to the public.
        $mod = Mod::query()->withoutGlobalScopes()->find($version->mod_id);
        $hidden = $version->disabled
            || $version->published_at === null
            || $version->published_at->isFuture()
            || $mod === null
            || $mod->disabled
            || ! $mod->isPublished();

        if ($hidden) {
            return;
        }

        self::query()->upsert(
            [[
                'user_id' => $user->id,
                'mod_id' => $version->mod_id,
                'mod_version_id' => $version->id,
                'version' => $version->version,
                'downloaded_at' => now(),
            ]],
            ['user_id', 'mod_id'],
            ['mod_version_id', 'version', 'downloaded_at'],
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Mod, $this>
     */
    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class);
    }

    /**
     * @return BelongsTo<ModVersion, $this>
     */
    public function modVersion(): BelongsTo
    {
        return $this->belongsTo(ModVersion::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'mod_id' => 'integer',
            'mod_version_id' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }
}
