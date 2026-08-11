<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Messages\NotificationMailMessage;
use App\Traits\ThrottlesOutboundEmail;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Delivers the link that hands an archived account back to its owner.
 *
 * Routed on demand to the address the claimant supplied, never through the user model: an archived accounts stored
 * address is an anonymised placeholder on a domain that does not resolve.
 */
final class AccountRecoveryNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(private readonly string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the recovery email using our standard branded template.
     */
    public function toMail(object $notifiable): NotificationMailMessage
    {
        $minutes = config()->integer('recovery.expiry_minutes', 60);

        return (new NotificationMailMessage)
            ->subject('Recover your Forge account')
            ->greeting('Recover your account')
            ->line('This address was used on the old Forge. Follow the link below to take back the account attached to it, along with your mods, comments and history.')
            ->action('Recover my account', route('account.recovery.redeem', ['token' => $this->token]))
            ->line(sprintf('The link expires in %d minutes and can only be used once.', $minutes))
            ->footer('If you did not request this, no action is needed and the account stays as it is.');
    }

    /**
     * @return array<int, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }

    /**
     * Stop retrying once the link inside would be dead anyway.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(config()->integer('recovery.expiry_minutes', 60));
    }
}
