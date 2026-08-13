<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MailRelayTransportMap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

#[Description('Render MAIL_RELAY_DOMAIN_PATTERN into the Postfix transport table that routes matching recipients via the relay.')]
#[Signature('mail:sync-relay-transport {--dry-run : Render and validate the table, then print it instead of installing it}')]
final class SyncMailRelayTransportCommand extends Command
{
    public function handle(): int
    {
        $map = MailRelayTransportMap::fromConfig();

        try {
            $table = $map->render();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        $staging = storage_path('app/transport_regexp');
        File::put($staging, $table);

        try {
            $rejection = $this->reject($staging);

            if ($rejection !== null) {
                $this->error($rejection);

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->line($table);
                $this->info($map->isEmpty() ? 'Valid. No recipient is relayed.' : 'Valid. Not installed (--dry-run).');

                return self::SUCCESS;
            }

            return $this->install($staging);
        } finally {
            File::delete($staging);
        }
    }

    /**
     * Why the rendered table must not be installed, or null when it is safe. Postfix itself is the only
     * authority on whether its expression parses, so the table is queried rather than re-parsed here.
     */
    private function reject(string $staging): ?string
    {
        $probe = Process::run(['postmap', '-q', 'canary@example.invalid', 'regexp:'.$staging]);

        // A miss exits non-zero with no output; only a malformed table says anything on stderr.
        if (mb_trim($probe->errorOutput()) !== '') {
            return 'Postfix rejected the expression: '.mb_trim($probe->errorOutput());
        }

        /** @var list<string> $neverRelay */
        $neverRelay = config()->array('mail-relay.never_relay');

        foreach ($neverRelay as $address) {
            $match = Process::run(['postmap', '-q', $address, 'regexp:'.$staging]);

            if (mb_trim($match->output()) !== '') {
                return sprintf(
                    'Pattern is too broad: it matches %s, which must never be relayed. The relay mailbox is capped near 500 recipients a day.',
                    $address,
                );
            }
        }

        return null;
    }

    /**
     * Put the table where Postfix reads it. A regexp table is read as-is, so unlike a hash table there is
     * nothing to compile — only the running processes have to be told to re-read it.
     */
    private function install(string $staging): int
    {
        $target = config()->string('mail-relay.map_path');

        $install = Process::run(['sudo', 'install', '-m', '0644', '-o', 'root', '-g', 'root', $staging, $target]);

        if (! $install->successful()) {
            $this->error('Could not write '.$target.': '.mb_trim($install->errorOutput()));

            return self::FAILURE;
        }

        $reload = Process::run(['sudo', 'systemctl', 'reload', 'postfix']);

        if (! $reload->successful()) {
            $this->error('Wrote '.$target.' but could not reload Postfix: '.mb_trim($reload->errorOutput()));

            return self::FAILURE;
        }

        $this->info(sprintf('%s updated and Postfix reloaded.', $target));

        return self::SUCCESS;
    }
}
