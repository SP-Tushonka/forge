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

final class ModIssueFixReleasedNotification extends Notification implements Presentable, ShouldQueue
{
    use DeliversModIssueNotifications;
    use Queueable;
    use ThrottlesOutboundEmail;

    public function __construct(
        public ModIssue $issue,
        public string $version,
    ) {}

    public static function presentDatabaseNotification(DatabaseNotification $record): NotificationPresentation
    {
        /** @var array{issue_title?: string, issue_url?: string, mod_name?: string, version?: string} $data */
        $data = $record->data;

        $version = $data['version'] ?? '';

        return new NotificationPresentation(
            iconName: 'check-badge',
            iconColorRole: NotificationColorRole::Blue,
            headline: [
                HeadlineSegment::accent(Str::limit($data['issue_title'] ?? '', 40)),
                HeadlineSegment::muted(' '.__('is fixed in').' '),
                HeadlineSegment::strong($version),
            ],
            summary: __('fix released in :version', ['version' => $version]),
            preview: $data['mod_name'] ?? null,
            previewQuoted: false,
            url: $data['issue_url'] ?? null,
        );
    }

    public function toMail(User $notifiable): NotificationMailMessage
    {
        $modName = $this->issue->mod->name;

        return (new NotificationMailMessage)
            ->subject(sprintf('Fixed in %s %s: %s', $modName, $this->version, $this->issue->getTitle()))
            ->greeting('The fix is out')
            ->line(sprintf('**%s** on **%s** is fixed in version **%s**, which is now available.', $this->issue->getTitle(), $modName, $this->version))
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
            'version' => $this->version,
        ];
    }
}
