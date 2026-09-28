<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
use App\Enums\NotificationColorRole;
use App\Models\User;
use App\Notifications\Messages\NotificationMailMessage;
use App\Support\DataTransferObjects\HeadlineSegment;
use App\Support\DataTransferObjects\NotificationPresentation;
use App\Traits\ThrottlesOutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Tells admins that accounts newly matched an alt watch. It names the accounts and the kinds of indicator that
 * matched, never the IPs, device hashes or other raw values, so none of those end up in a mailbox.
 */
final class AltWatchMatchedNotification extends Notification implements Presentable, ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    /**
     * @param  list<array{id: int, name: string}>  $accounts
     * @param  list<string>  $kinds  Labels of the indicator types that matched
     */
    public function __construct(
        public readonly int $watchId,
        public readonly string $watchedUserName,
        public readonly string $reason,
        public readonly array $accounts,
        public readonly array $kinds,
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{watch_id?: int, watched_user_name?: string, accounts?: list<array{id: int, name: string}>} $data */
        $data = $record->data;

        $name = $data['watched_user_name'] ?? __('a user');
        $names = array_column($data['accounts'] ?? [], 'name');
        $count = count($names);

        return new NotificationPresentation(
            iconName: 'finger-print',
            iconColorRole: NotificationColorRole::Amber,
            headline: [
                HeadlineSegment::strong(trans_choice(':count account|:count accounts', $count, ['count' => $count])),
                HeadlineSegment::muted(' '.__('matched the watch on').' '),
                HeadlineSegment::accent($name),
            ],
            summary: __('matched the watch on').' '.Str::limit($name, 30),
            preview: $names === [] ? null : Str::limit(implode(', ', $names), 150),
            previewQuoted: false,
            url: isset($data['watch_id']) ? route('admin.alt-monitoring.watches.show', $data['watch_id']) : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User && $notifiable->email_alt_alert_notifications_enabled) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): NotificationMailMessage
    {
        $count = count($this->accounts);
        $subject = sprintf('Alt monitoring: %d new %s for %s', $count, Str::plural('match', $count), $this->watchedUserName);

        return (new NotificationMailMessage)
            ->subject($subject)
            ->greeting($subject)
            ->line(sprintf('%s newly matched the watch on %s.', Arr::join(array_column($this->accounts, 'name'), ', ', ' and '), $this->watchedUserName))
            ->line('**Matched on:** '.implode(', ', $this->kinds))
            ->line('**Reason for the watch:** '.$this->reason)
            ->action('Review Matches', route('admin.alt-monitoring.watches.show', $this->watchId))
            ->footer('You get this email because you are an admin with alt monitoring alerts turned on. You can turn them off in your notification preferences.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'watch_id' => $this->watchId,
            'watched_user_name' => $this->watchedUserName,
            'accounts' => $this->accounts,
            'kinds' => $this->kinds,
        ];
    }
}
