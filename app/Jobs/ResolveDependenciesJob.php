<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ModVersion;
use App\Services\DependencyVersionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Timeout(120)]
#[Backoff([1, 5, 10])]
#[Tries(3)]
final class ResolveDependenciesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Bounded so a worker killed mid run cannot hold the uniqueness lock forever and block every
     * later dispatch. Must exceed the timeout above.
     */
    public int $uniqueFor = 180;

    /**
     * Rebuild the resolved dependency pivot for every mod version that needs one.
     */
    public function handle(DependencyVersionService $dependencyVersionService): void
    {
        ModVersion::query()
            // bring in only mods with dependencies, no need to check absolutely everything
            ->whereHas('dependencies')
            // chink the results rather than mass collecting
            ->chunkById(100, function (Collection $modVersions) use ($dependencyVersionService): void {
                foreach ($modVersions as $modVersion) {
                    $dependencyVersionService->resolve($modVersion);
                }
            });
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('ResolveDependenciesJob failed', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
