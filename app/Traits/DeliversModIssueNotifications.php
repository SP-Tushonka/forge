<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\User;

trait DeliversModIssueNotifications
{
    /**
     * Channels follow the recipient's issue notification setting. An on-demand notifiable has no such setting.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User ? $notifiable->issueNotificationLevel()->channels() : [];
    }

    protected function mailFooter(): string
    {
        return sprintf(
            'You can mute this issue, or every issue on the mod, from its page. You can also change your issue notifications in your [profile settings](%s).',
            route('profile.show'),
        );
    }
}
