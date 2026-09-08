<?php

declare(strict_types=1);

use App\Enums\ModOwnershipChange;
use App\Models\User;
use App\Notifications\ModOwnershipChangedNotification;
use App\Traits\ThrottlesOutboundEmail;

it('uses the outbound email throttle', function (): void {
    // ArchTest asserts this repo-wide; asserted again here because omitting it silently
    // bypasses the shared SES sending quota.
    expect(class_uses_recursive(ModOwnershipChangedNotification::class))
        ->toContain(ThrottlesOutboundEmail::class);
});

it('sends on both channels for a user and mail only for an on demand notifiable', function (): void {
    $notification = new ModOwnershipChangedNotification(
        ModOwnershipChange::Transferred,
        'Example Mod',
        'https://example.test/mod/1/example-mod',
        isNewOwner: false,
        reason: 'Original author requested it',
    );

    expect($notification->via(User::factory()->create()))->toBe(['database', 'mail'])
        ->and($notification->via(new stdClass))->toBe(['mail']);
});

it('tells the previous owner the mod moved away', function (): void {
    $notification = new ModOwnershipChangedNotification(
        ModOwnershipChange::Transferred,
        'Example Mod',
        'https://example.test/mod/1/example-mod',
        isNewOwner: false,
    );

    $mail = $notification->toMail(User::factory()->create());

    expect($mail->subject)->toContain('Example Mod');
});

it('tells the new owner they received the mod', function (): void {
    $notification = new ModOwnershipChangedNotification(
        ModOwnershipChange::Transferred,
        'Example Mod',
        'https://example.test/mod/1/example-mod',
        isNewOwner: true,
    );

    expect($notification->toArray(User::factory()->create()))
        ->toMatchArray([
            'change' => 'transferred',
            'mod_name' => 'Example Mod',
            'is_new_owner' => true,
        ]);
});

it('includes the staff reason in the payload when given', function (): void {
    $notification = new ModOwnershipChangedNotification(
        ModOwnershipChange::Cleared,
        'Example Mod',
        'https://example.test/mod/1/example-mod',
        isNewOwner: false,
        reason: 'Owner account was compromised',
    );

    expect($notification->toArray(User::factory()->create())['reason'])
        ->toBe('Owner account was compromised');
});
