<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\NotificationMailMessage;
use App\Traits\ThrottlesOutboundEmail;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Tells a member their account was signed in from a browser it had not used before, or from one they had signed out.
 * A security notice, so it bypasses notification preferences.
 */
final class NewDeviceLoginNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public readonly string $device,
        public readonly ?string $location,
        public readonly ?string $ip,
        public readonly CarbonImmutable $signedInAt,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): NotificationMailMessage
    {
        $timezone = $notifiable instanceof User && is_string($notifiable->timezone) && $notifiable->timezone !== '' ? $notifiable->timezone : 'UTC';

        $subject = 'New sign-in to your account';

        return (new NotificationMailMessage)
            ->subject($subject)
            ->greeting($subject)
            ->line(sprintf('Your %s account was just signed in with the following details:', config()->string('app.name')))
            ->line('**Device:** '.$this->device)
            ->line('**Location:** '.($this->location ?? 'Unknown'))
            ->line('**IP address:** '.($this->ip ?? 'Unknown'))
            ->line('**Time:** '.$this->signedInAt->setTimezone($timezone)->format('F j, Y \a\t g:i A T'))
            ->line('')
            ->line('If this was you, there is nothing to do.')
            ->action('Review Your Devices', route('profile.show').'#devices')
            ->line('If it was not you, change your password first, then sign that device out from your devices list and turn on two-factor authentication.')
            ->footer('We send this email whenever your account is signed in from a browser it has not used before. It cannot be turned off because it protects your account.');
    }

    /**
     * @return array<int, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
