<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\UpdateEndorsementsJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Recalculate denormalized endorsement counts for all mods')]
#[Signature('app:update-endorsements')]
final class UpdateModEndorsementsCommand extends Command
{
    public function handle(): void
    {
        dispatch(new UpdateEndorsementsJob())->onQueue('default');

        $this->info('UpdateEndorsementsJob added to the queue');
    }
}
