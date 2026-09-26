<?php

declare(strict_types=1);

use App\Models\Ban;
use App\Models\User;
use App\Services\BanIdentifierService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Give bans already in force their copy of the account's emails and IPs. Must land before the first retention run,
     * which scrubs the comment IPs that most of these bans' addresses come from.
     */
    public function up(): void
    {
        $service = resolve(BanIdentifierService::class);

        Ban::query()
            ->where('bannable_type', (new User)->getMorphClass())
            ->where(fn (Builder $query) => $query->notExpired())
            ->with('bannable')
            ->each(function (Ban $ban) use ($service): void {
                if ($ban->bannable instanceof User) {
                    $service->capture($ban, $ban->bannable);
                }
            });
    }
};
