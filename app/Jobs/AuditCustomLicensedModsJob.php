<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\License;
use App\Models\Mod;
use App\Notifications\CustomLicenseUnpublishedNotification;
use App\Services\License\CustomLicenseVerificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes sure no one is sneaking out their custom license
 */
#[Timeout(1800)]
#[Tries(1)]
final class AuditCustomLicensedModsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(CustomLicenseVerificationService $customLicense): void
    {
        $license = License::query()->where('name', License::CUSTOM_NAME)->first();

        if ($license === null) {
            return;
        }

        Mod::query()
            ->withoutGlobalScopes()
            ->with(['sourceCodeLinks', 'owner'])
            ->where('license_id', $license->id)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->chunkById(100, function (Collection $mods) use ($customLicense): void {
                foreach ($mods as $mod) {
                    $this->audit($mod, $customLicense);
                }
            });
    }

    /**
     * Handle a job failure
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('AuditCustomLicensedModsJob failed', ['error' => $exception?->getMessage()]);
    }

    private function audit(Mod $mod, CustomLicenseVerificationService $customLicense): void
    {
        /** @var list<string> $urls */
        $urls = $mod->sourceCodeLinks->pluck('url')->values()->all();

        if ($customLicense->passesUnthrottled($urls)) {
            return;
        }

        $mod->published_at = null;
        $mod->save();

        $owner = $mod->owner;

        if ($owner === null) {
            Log::info('Unpublished an unowned custom-licensed mod', ['mod_id' => $mod->id]);

            return;
        }

        $owner->notify(new CustomLicenseUnpublishedNotification($mod));
    }
}
