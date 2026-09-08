<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
use App\Enums\ModOwnershipChange;
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

final class ModOwnershipChangedNotification extends Notification implements Presentable, ShouldQueue
{
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public ModOwnershipChange $change,
        public string $modName,
        public string $modUrl,
        public bool $isNewOwner,
        public ?string $reason = null,
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{change?: string, mod_name?: string, mod_url?: string, is_new_owner?: bool, reason?: ?string} $data */
        $data = $record->data;

        $change = ModOwnershipChange::tryFrom($data['change'] ?? '') ?? ModOwnershipChange::Transferred;
        $modName = $data['mod_name'] ?? '';
        $isNewOwner = (bool) ($data['is_new_owner'] ?? false);
        $reason = $data['reason'] ?? null;

        return new NotificationPresentation(
            iconName: $change === ModOwnershipChange::Transferred ? 'arrows-right-left' : 'user-minus',
            iconColorRole: $change === ModOwnershipChange::Transferred ? NotificationColorRole::Blue : NotificationColorRole::Red,
            headline: [
                HeadlineSegment::strong(__('Mod ownership')),
                HeadlineSegment::muted(' - '),
                HeadlineSegment::accent($modName),
            ],
            summary: $change->subject($isNewOwner, $modName),
            preview: $reason !== null && $reason !== '' ? $reason : null,
            previewQuoted: true,
        );
    }

    /**
     * Ownership notices always reach the user, regardless of their moderation email preference.
     * This matches StaffAccountActionNotification.
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
        $subject = $this->change->subject($this->isNewOwner, $this->modName);

        $message = (new NotificationMailMessage)
            ->subject($subject)
            ->greeting($subject)
            ->line($this->change->body($this->isNewOwner))
            ->line('**Mod:** ['.$this->modName.']('.$this->modUrl.')');

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
            'change' => $this->change->value,
            'mod_name' => $this->modName,
            'mod_url' => $this->modUrl,
            'is_new_owner' => $this->isNewOwner,
            'reason' => $this->reason,
        ];
    }
}
