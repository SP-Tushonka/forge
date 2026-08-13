<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config()->set('mail-relay.pattern', 'gmx\.(de|net)|web\.de');
    config()->set('mail-relay.transport', 'gmailrelay:[smtp.gmail.com]:587');
    config()->set('mail-relay.map_path', '/etc/postfix/transport_regexp');
    config()->set('mail-relay.never_relay', ['canary@gmail.com']);
});

describe('mail:sync-relay-transport', function (): void {
    it('installs the table and reloads Postfix', function (): void {
        Process::fake();

        $this->artisan('mail:sync-relay-transport')
            ->expectsOutputToContain('/etc/postfix/transport_regexp updated')
            ->assertSuccessful();

        Process::assertRan(fn (Illuminate\Process\PendingProcess $process): bool => str_contains(
            is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
            'systemctl reload postfix',
        ));
    });

    it('leaves the running configuration alone on a dry run', function (): void {
        Process::fake();

        $this->artisan('mail:sync-relay-transport', ['--dry-run' => true])
            ->expectsOutputToContain('/(^|@)(gmx\.(de|net)|web\.de)$/')
            ->assertSuccessful();

        Process::assertDidntRun(fn (Illuminate\Process\PendingProcess $process): bool => str_contains(
            is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
            'sudo',
        ));
    });

    it('refuses an expression Postfix cannot parse', function (): void {
        Process::fake(['*postmap*' => Process::result(errorOutput: 'postmap: warning: bad regular expression', exitCode: 1)]);

        $this->artisan('mail:sync-relay-transport')
            ->expectsOutputToContain('Postfix rejected the expression')
            ->assertFailed();
    });

    it('refuses a pattern broad enough to exhaust the relay mailbox', function (): void {
        config()->set('mail-relay.pattern', '.+');

        Process::fake([
            '*canary@gmail.com*' => Process::result(output: 'gmailrelay:[smtp.gmail.com]:587'),
            '*' => Process::result(),
        ]);

        $this->artisan('mail:sync-relay-transport')
            ->expectsOutputToContain('too broad')
            ->assertFailed();

        Process::assertDidntRun(fn (Illuminate\Process\PendingProcess $process): bool => str_contains(
            is_array($process->command) ? implode(' ', $process->command) : (string) $process->command,
            'install',
        ));
    });

    it('reports a rejected pattern without leaving the staged table behind', function (): void {
        config()->set('mail-relay.pattern', 'gmx\.de/');

        Process::fake();

        $this->artisan('mail:sync-relay-transport')
            ->expectsOutputToContain('cannot contain "/"')
            ->assertFailed();

        expect(File::exists(storage_path('app/transport_regexp')))->toBeFalse();
    });
});
