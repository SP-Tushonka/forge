<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
use App\Enums\NotificationColorRole;
use App\Models\Mod;
use App\Models\User;
use App\Notifications\Messages\NotificationMailMessage;
use App\Support\DataTransferObjects\HeadlineSegment;
use App\Support\DataTransferObjects\NotificationPresentation;
use App\Traits\ThrottlesOutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class CustomLicenseUnpublishedNotification extends Notification implements Presentable, ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    /**
     * Create a new notification instance
     */
    public function __construct(
        public Mod $mod
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{mod_name?: string, mod_url?: string} $data */
        $data = $record->data;

        $name = $data['mod_name'] ?? __('Your mod');

        return new NotificationPresentation(
            iconName: 'exclamation-triangle',
            iconColorRole: NotificationColorRole::Amber,
            headline: [
                HeadlineSegment::accent(Str::limit($name, 40)),
                HeadlineSegment::muted(' '.__('has been unpublished until you fix your license').'.'),
            ],
            summary: __('unpublished until you fix your license'),
            preview: null,
            previewQuoted: false,
            url: $data['mod_url'] ?? null,
        );
    }

    /**
     * Get the notifications delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->email_moderation_notifications_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification
     */
    public function toMail(object $notifiable): NotificationMailMessage
    {
        return (new NotificationMailMessage)
            ->subject('Your mod '.$this->mod->name.' has been unpublished')
            ->greeting('Your mod has been unpublished')
            ->line(sprintf('Your mod [%s](%s) has been unpublished until you fix your license on your mod.', $this->mod->name, $this->mod->detail_url))
            ->action('View Mod', $this->mod->detail_url);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'mod_id' => $this->mod->id,
            'mod_name' => $this->mod->name,
            'mod_url' => $this->mod->detail_url,
        ];
    }
}
