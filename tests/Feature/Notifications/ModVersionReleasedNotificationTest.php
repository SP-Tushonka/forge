<?php

declare(strict_types=1);

use App\Enums\NotificationColorRole;
use App\Models\User;
use App\Notifications\ModVersionReleasedNotification;

function releasedNotification(?string $sptVersion = 'SPT 3.11.4'): ModVersionReleasedNotification
{
    return new ModVersionReleasedNotification(
        modId: 42,
        modName: 'SAIN',
        modUrl: 'https://forge.test/mod/42/sain',
        version: '4.1.0',
        sptVersion: $sptVersion,
    );
}

describe('Channel Selection', function (): void {
    it('sends via the database channel only, whatever the mod update preference', function (): void {
        $enabled = User::factory()->create(['email_mod_update_notifications_enabled' => true]);
        $disabled = User::factory()->create(['email_mod_update_notifications_enabled' => false]);

        expect(releasedNotification()->via($enabled))->toBe(['database'])
            ->and(releasedNotification()->via($disabled))->toBe(['database']);
    });
});

describe('Release Shape', function (): void {
    it('exposes the release as a plain array for the digest to collect', function (): void {
        expect(releasedNotification()->release())->toBe([
            'mod_id' => 42,
            'mod_name' => 'SAIN',
            'mod_url' => 'https://forge.test/mod/42/sain',
            'version' => '4.1.0',
            'spt_version' => 'SPT 3.11.4',
        ]);
    });
});

describe('Presentation', function (): void {
    it('presents the release with the mod, version and SPT version', function (): void {
        $user = User::factory()->create();
        $user->notify(releasedNotification());
        $record = $user->notifications()->sole();

        $presentation = ModVersionReleasedNotification::presentDatabaseNotification($record);

        expect($presentation->iconName)->toBe('bell-alert')
            ->and($presentation->iconColorRole)->toBe(NotificationColorRole::Blue)
            ->and(collect($presentation->headline)->pluck('text')->implode(''))->toBe('SAIN released v4.1.0')
            ->and($presentation->summary)->toBe('new version for SPT 3.11.4')
            ->and($presentation->url)->toBe('https://forge.test/mod/42/sain');
    });

    it('falls back when the SPT version is unknown', function (): void {
        $user = User::factory()->create();
        $user->notify(releasedNotification(null));

        $presentation = ModVersionReleasedNotification::presentDatabaseNotification($user->notifications()->sole());

        expect($presentation->summary)->toBe('new version');
    });
});
