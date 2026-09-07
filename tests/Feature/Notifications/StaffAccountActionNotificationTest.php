<?php

declare(strict_types=1);

use App\Enums\StaffActionType;
use App\Models\User;
use App\Notifications\StaffAccountActionNotification;
use Illuminate\Notifications\AnonymousNotifiable;

it('sends database and mail to a user notifiable', function (): void {
    $user = User::factory()->create();
    $notification = new StaffAccountActionNotification(StaffActionType::TwoFactorRemoved, 'Support request');

    expect($notification->via($user))->toBe(['database', 'mail']);
});

it('sends mail only to an on-demand notifiable', function (): void {
    $notification = new StaffAccountActionNotification(StaffActionType::EmailRemoved, 'Abuse');

    expect($notification->via(new AnonymousNotifiable))->toBe(['mail']);
});

it('ignores the moderation email preference', function (): void {
    $user = User::factory()->create(['email_moderation_notifications_enabled' => false]);
    $notification = new StaffAccountActionNotification(StaffActionType::AccountLocked, null);

    expect($notification->via($user))->toContain('mail');
});

it('includes the staff reason in the mail body', function (): void {
    $user = User::factory()->create();
    $notification = new StaffAccountActionNotification(StaffActionType::TwoFactorRemoved, 'Lost their phone');

    $rendered = (string) $notification->toMail($user)->render();

    expect($rendered)->toContain('Lost their phone');
});

it('renders without a reason', function (): void {
    $user = User::factory()->create();
    $notification = new StaffAccountActionNotification(StaffActionType::PhotoRemoved, null);

    expect((string) $notification->toMail($user)->render())->toContain('A profile image was removed');
});
