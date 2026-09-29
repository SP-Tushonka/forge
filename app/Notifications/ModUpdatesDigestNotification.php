<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\NotificationMailMessage;
use App\Traits\ThrottlesOutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Mails a subscriber at most one email per sweep listing every mod that released a new version, so a subscriber of
 * several mods doesn't get flooded when they all update together. The on-site alert stays per-mod, sent separately by
 * ModVersionReleasedNotification.
 */
final class ModUpdatesDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    /**
     * @param  list<array{mod_id: int, mod_name: string, mod_url: string, version: string, spt_version: string|null}>  $releases
     */
    public function __construct(public array $releases) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof User
            && $notifiable->hasVerifiedEmail()
            && $notifiable->email_mod_update_notifications_enabled) {
            return ['mail'];
        }

        return [];
    }

    public function toMail(User $notifiable): NotificationMailMessage
    {
        return count($this->releases) === 1
            ? $this->singleReleaseMail($notifiable, $this->releases[0])
            : $this->multiReleaseMail($notifiable);
    }

    /**
     * Escape markdown so a mod's free-text name (up to 75 chars) cannot inject links or formatting into the email.
     */
    private static function escapeMarkdown(string $name): string
    {
        return addcslashes($name, '\\`*_[]()<>#');
    }

    /**
     * @param  array{mod_id: int, mod_name: string, mod_url: string, version: string, spt_version: string|null}  $release
     */
    private function singleReleaseMail(User $notifiable, array $release): NotificationMailMessage
    {
        $unsubscribeUrl = URL::signedRoute('mod.unsubscribe', ['user' => $notifiable->id, 'modId' => $release['mod_id']]);
        $modName = self::escapeMarkdown($release['mod_name']);

        return (new NotificationMailMessage)
            ->subject(sprintf('%s v%s is out', $release['mod_name'], $release['version']))
            ->greeting('A new version is out')
            ->line($release['spt_version'] === null
                ? sprintf('**%s** has released version **%s**.', $modName, $release['version'])
                : sprintf('**%s** has released version **%s**, compatible with %s.', $modName, $release['version'], $release['spt_version']))
            ->action('View Mod', $release['mod_url'])
            ->footer(sprintf(
                'You can [unsubscribe](%s) from updates for this mod, or [manage all of your email preferences](%s).',
                $unsubscribeUrl,
                route('profile.show'),
            ));
    }

    private function multiReleaseMail(User $notifiable): NotificationMailMessage
    {
        $message = (new NotificationMailMessage)
            ->subject(sprintf('%d mods you subscribed to have new versions', count($this->releases)))
            ->greeting('New versions are out');

        foreach ($this->releases as $release) {
            $unsubscribeUrl = URL::signedRoute('mod.unsubscribe', ['user' => $notifiable->id, 'modId' => $release['mod_id']]);

            $message->line(sprintf(
                '- [%s v%s](%s)%s · [unsubscribe](%s)',
                self::escapeMarkdown($release['mod_name']),
                $release['version'],
                $release['mod_url'],
                $release['spt_version'] === null ? '' : ', compatible with '.$release['spt_version'],
                $unsubscribeUrl,
            ));
        }

        $message->action('View Notifications', route('notifications'));

        return $message->footer(sprintf(
            'Each unsubscribe link stops updates for that mod. You can also [manage all of your email preferences](%s).',
            route('profile.show'),
        ));
    }
}
