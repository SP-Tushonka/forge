<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
use App\Enums\ModIssueStatus;
use App\Enums\NotificationColorRole;
use App\Models\ModIssue;
use App\Models\User;
use App\Notifications\Messages\NotificationMailMessage;
use App\Support\DataTransferObjects\HeadlineSegment;
use App\Support\DataTransferObjects\NotificationPresentation;
use App\Traits\DeliversModIssueNotifications;
use App\Traits\ThrottlesOutboundEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class ModIssueStatusChangedNotification extends Notification implements Presentable, ShouldQueue
{
    use DeliversModIssueNotifications;
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public ModIssue $issue,
        public ModIssueStatus $from,
        public ModIssueStatus $to,
        public User $actor,
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{actor_name?: string, issue_title?: string, issue_url?: string, from_label?: string, to_label?: string} $data */
        $data = $record->data;

        $toLabel = $data['to_label'] ?? '';

        return new NotificationPresentation(
            iconName: 'arrow-path',
            iconColorRole: NotificationColorRole::Purple,
            headline: [
                HeadlineSegment::strong($data['actor_name'] ?? __('Someone')),
                HeadlineSegment::muted(' '.__('moved').' '),
                HeadlineSegment::accent(Str::limit($data['issue_title'] ?? '', 40)),
            ],
            summary: __('moved an issue to :status', ['status' => $toLabel]),
            preview: sprintf('%s → %s', $data['from_label'] ?? '', $toLabel),
            previewQuoted: false,
            url: $data['issue_url'] ?? null,
        );
    }

    public function toMail(User $notifiable): NotificationMailMessage
    {
        $type = $this->issue->type;
        $headline = sprintf('%s is now %s', $this->issue->getTitle(), $this->to->label($type));

        return (new NotificationMailMessage)
            ->subject($headline)
            ->greeting($headline)
            ->line(sprintf(
                '**%s** changed the status of **%s** on %s from **%s** to **%s**.',
                $this->actor->name,
                $this->issue->getTitle(),
                $this->issue->mod->name,
                $this->from->label($type),
                $this->to->label($type),
            ))
            ->action('View Issue', $this->issue->url())
            ->footer($this->mailFooter());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'issue_id' => $this->issue->id,
            'issue_title' => $this->issue->getTitle(),
            'issue_url' => $this->issue->url(),
            'mod_name' => $this->issue->mod->name,
            'actor_name' => $this->actor->name,
            'from_label' => $this->from->label($this->issue->type),
            'to_label' => $this->to->label($this->issue->type),
        ];
    }
}
