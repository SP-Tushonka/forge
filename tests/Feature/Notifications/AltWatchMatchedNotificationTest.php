<?php

declare(strict_types=1);

use App\Enums\NotificationColorRole;
use App\Models\User;
use App\Notifications\AltWatchMatchedNotification;

function sampleAltAlert(): AltWatchMatchedNotification
{
    return new AltWatchMatchedNotification(
        7,
        'Evader',
        'Came back after a permanent ban.',
        [['id' => 11, 'name' => 'FreshAlt'], ['id' => 12, 'name' => 'OtherAlt']],
        ['Device', 'IP range'],
    );
}

it('always goes to the notification centre, and by email only with the toggle on', function (): void {
    $on = User::factory()->admin()->create(['email_alt_alert_notifications_enabled' => true]);
    $off = User::factory()->admin()->create(['email_alt_alert_notifications_enabled' => false]);

    expect(sampleAltAlert()->via($on))->toBe(['database', 'mail'])
        ->and(sampleAltAlert()->via($off))->toBe(['database']);
});

it('names the accounts, the kinds of indicator and the reason in the email', function (): void {
    $rendered = (string) sampleAltAlert()->toMail(User::factory()->admin()->create())->render();

    expect($rendered)->toContain('FreshAlt and OtherAlt')
        ->toContain('Device, IP range')
        ->toContain('Came back after a permanent ban.')
        ->toContain(route('admin.alt-monitoring.watches.show', 7));
});

it('presents a match alert in the notification centre', function (): void {
    $admin = User::factory()->admin()->create();
    $record = $admin->notifications()->create([
        'id' => fake()->uuid(),
        'type' => AltWatchMatchedNotification::class,
        'data' => sampleAltAlert()->toArray($admin),
    ]);

    $presentation = AltWatchMatchedNotification::presentDatabaseNotification($record);

    expect($presentation->iconColorRole)->toBe(NotificationColorRole::Amber)
        ->and($presentation->headline[0]->text)->toBe('2 accounts')
        ->and($presentation->headline[2]->text)->toBe('Evader')
        ->and($presentation->preview)->toBe('FreshAlt, OtherAlt')
        ->and($presentation->url)->toBe(route('admin.alt-monitoring.watches.show', 7));
});
