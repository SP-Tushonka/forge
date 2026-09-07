<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
use App\Enums\NotificationColorRole;
use App\Enums\StaffActionType;
use App\Models\User;
use App\Notifications\Messages\NotificationMailMessage;
use App\Support\DataTransferObjects\HeadlineSegment;
use App\Support\DataTransferObjects\NotificationPresentation;
use App\Traits\ThrottlesOutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

final class StaffAccountActionNotification extends Notification implements Presentable, ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public StaffActionType $action,
        public ?string $reason = null,
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{action?: string, reason?: ?string} $data */
        $data = $record->data;

        $action = StaffActionType::tryFrom($data['action'] ?? '') ?? StaffActionType::AccountLocked;
        $reason = $data['reason'] ?? null;

        return new NotificationPresentation(
            iconName: 'shield-exclamation',
            iconColorRole: NotificationColorRole::Red,
            headline: [
                HeadlineSegment::strong(__('Account security')),
                HeadlineSegment::muted(' - '),
                HeadlineSegment::accent(__($action->subject())),
            ],
            summary: __($action->subject()),
            preview: $reason !== null && $reason !== '' ? $reason : null,
            previewQuoted: true,
        );
    }

    /**
     * Account security notices always reach the user, regardless of their
     * moderation email preference. This matches UserBannedNotification.
     *
     * An on-demand notifiable has no database channel.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? ['database', 'mail'] : ['mail'];
    }

    public function toMail(object $notifiable): NotificationMailMessage
    {
        $subject = $this->action->subject();

        $message = (new NotificationMailMessage)
            ->subject($subject)
            ->greeting($subject)
            ->line($this->action->body());

        if ($this->reason !== null && $this->reason !== '') {
            $message->line('**Reason given by staff:** '.$this->reason);
        }

        $message->line('');
        $message->line('If you believe this was done in error, please contact us.');

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'action' => $this->action->value,
            'reason' => $this->reason,
        ];
    }
}
