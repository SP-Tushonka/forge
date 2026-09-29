<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\ModUpdatesDigestNotification;

/**
 * @param  array<string, mixed>  $overrides
 * @return array{mod_id: int, mod_name: string, mod_url: string, version: string, spt_version: string|null}
 */
function digestRelease(array $overrides = []): array
{
    return [
        'mod_id' => 42,
        'mod_name' => 'SAIN',
        'mod_url' => 'https://forge.test/mod/42/sain',
        'version' => '4.1.0',
        'spt_version' => 'SPT 3.11.4',
        ...$overrides,
    ];
}

describe('Channel Selection', function (): void {
    it('sends via mail when verified and the mod update preference is enabled', function (): void {
        $user = User::factory()->create(['email_mod_update_notifications_enabled' => true]);

        expect((new ModUpdatesDigestNotification([digestRelease()]))->via($user))->toBe(['mail']);
    });

    it('omits mail when the mod update preference is disabled', function (): void {
        $user = User::factory()->create(['email_mod_update_notifications_enabled' => false]);

        expect((new ModUpdatesDigestNotification([digestRelease()]))->via($user))->toBe([]);
    });

    it('omits mail when the email is not verified', function (): void {
        $user = User::factory()->unverified()->create(['email_mod_update_notifications_enabled' => true]);

        expect((new ModUpdatesDigestNotification([digestRelease()]))->via($user))->toBe([]);
    });
});

describe('Single Release Mail', function (): void {
    it('reads like a single mod release email, with a signed unsubscribe link for that mod', function (): void {
        $user = User::factory()->create();

        $message = (new ModUpdatesDigestNotification([digestRelease()]))->toMail($user);

        expect($message->subject)->toBe('SAIN v4.1.0 is out')
            ->and(implode(' ', $message->introLines))->toContain('**SAIN** has released version **4.1.0**, compatible with SPT 3.11.4.')
            ->and($message->actionUrl)->toBe('https://forge.test/mod/42/sain');

        $footer = implode(' ', $message->footerLines);
        expect($footer)
            ->toContain(route('mod.unsubscribe', ['user' => $user->id, 'modId' => 42], absolute: false))
            ->toContain('signature=')
            ->toContain(route('profile.show'));
    });
});

describe('Multi Release Mail', function (): void {
    it('lists every release with its own signed unsubscribe link', function (): void {
        $user = User::factory()->create();
        $releases = [
            digestRelease(['mod_id' => 1, 'mod_name' => 'SAIN', 'mod_url' => 'https://forge.test/mod/1/sain', 'version' => '4.1.0', 'spt_version' => 'SPT 3.11.4']),
            digestRelease(['mod_id' => 2, 'mod_name' => 'Fika', 'mod_url' => 'https://forge.test/mod/2/fika', 'version' => '2.0.0', 'spt_version' => null]),
        ];

        $message = (new ModUpdatesDigestNotification($releases))->toMail($user);

        expect($message->subject)->toBe('2 mods you subscribed to have new versions')
            ->and($message->actionUrl)->toBe(route('notifications'));

        $intro = implode(' ', $message->introLines);
        expect($intro)
            ->toContain('[SAIN v4.1.0](https://forge.test/mod/1/sain), compatible with SPT 3.11.4')
            ->toContain('[Fika v2.0.0](https://forge.test/mod/2/fika)')
            ->toContain(route('mod.unsubscribe', ['user' => $user->id, 'modId' => 1], absolute: false))
            ->toContain(route('mod.unsubscribe', ['user' => $user->id, 'modId' => 2], absolute: false));

        expect(implode(' ', $message->footerLines))->toContain(route('profile.show'));
    });

    it('escapes a mod name that attempts to inject markdown into the line', function (): void {
        $user = User::factory()->create();
        $releases = [
            digestRelease(['mod_id' => 1, 'mod_name' => 'One']),
            digestRelease(['mod_id' => 2, 'mod_name' => '[x](https://evil.test)']),
        ];

        $intro = implode(' ', (new ModUpdatesDigestNotification($releases))->toMail($user)->introLines);

        expect($intro)
            ->not->toContain('](https://evil.test)')
            ->toContain('\[x\]');
    });
});
