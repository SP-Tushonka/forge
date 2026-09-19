<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\Presentable;
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

final class NewModIssueNotification extends Notification implements Presentable, ShouldQueue
{
    use DeliversModIssueNotifications;
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public ModIssue $issue
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{reporter_name?: string, issue_title?: string, mod_name?: string, issue_url?: string} $data */
        $data = $record->data;

        $modName = $data['mod_name'] ?? '';
        $title = $data['issue_title'] ?? '';

        return new NotificationPresentation(
            iconName: 'bug-ant',
            iconColorRole: NotificationColorRole::Blue,
            headline: [
                HeadlineSegment::strong($data['reporter_name'] ?? __('Someone')),
                HeadlineSegment::muted(' '.__('opened an issue on').' '),
                HeadlineSegment::accent(Str::limit($modName, 40)),
            ],
            summary: __('opened an issue on').' '.Str::limit($modName, 30),
            preview: $title !== '' ? Str::limit($title, 150) : null,
            previewQuoted: false,
            url: $data['issue_url'] ?? null,
        );
    }

    public function toMail(User $notifiable): NotificationMailMessage
    {
        $modName = $this->issue->mod->name;

        return (new NotificationMailMessage)
            ->subject(sprintf('New issue on %s: %s', $modName, $this->issue->getTitle()))
            ->greeting(sprintf('New issue on %s', $modName))
            ->line(sprintf('**%s** opened a new %s: **%s**.', $this->issue->user->name, Str::lower($this->issue->type->label()), $this->issue->getTitle()))
            ->line('')
            ->line('> '.Str::limit($this->issue->body, 500))
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
            'reporter_name' => $this->issue->user->name,
        ];
    }
}
