<?php

declare(strict_types=1);

use App\Enums\IssueNotificationLevel;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public bool $emailAnnouncementNotificationsEnabled = true;

    public bool $emailCommentNotificationsEnabled = true;

    public bool $emailReplyNotificationsEnabled = true;

    public bool $emailChatNotificationsEnabled = true;

    public bool $emailModUpdateNotificationsEnabled = true;

    public bool $emailModerationNotificationsEnabled = true;

    public bool $emailAltAlertNotificationsEnabled = true;

    public string $issueNotifications = 'all';

    public function mount(): void
    {
        $user = Auth::user();
        $this->emailAnnouncementNotificationsEnabled = $user->email_announcement_notifications_enabled ?? true;
        $this->emailCommentNotificationsEnabled = $user->email_comment_notifications_enabled ?? true;
        $this->emailReplyNotificationsEnabled = $user->email_reply_notifications_enabled ?? true;
        $this->emailChatNotificationsEnabled = $user->email_chat_notifications_enabled ?? true;
        $this->emailModUpdateNotificationsEnabled = $user->email_mod_update_notifications_enabled ?? true;
        $this->emailModerationNotificationsEnabled = $user->email_moderation_notifications_enabled ?? true;
        $this->emailAltAlertNotificationsEnabled = $user->email_alt_alert_notifications_enabled ?? true;
        $this->issueNotifications = $user?->issueNotificationLevel()->value ?? IssueNotificationLevel::All->value;
    }

    public function updatedIssueNotifications(): void
    {
        $this->updateNotificationPreferences();
    }

    public function updateNotificationPreferences(): void
    {
        /** @var User $user */
        $user = Auth::user();

        $preferences = [
            'email_announcement_notifications_enabled' => $this->emailAnnouncementNotificationsEnabled,
            'email_comment_notifications_enabled' => $this->emailCommentNotificationsEnabled,
            'email_reply_notifications_enabled' => $this->emailReplyNotificationsEnabled,
            'email_chat_notifications_enabled' => $this->emailChatNotificationsEnabled,
            'email_mod_update_notifications_enabled' => $this->emailModUpdateNotificationsEnabled,
            'issue_notifications' => IssueNotificationLevel::tryFrom($this->issueNotifications) ?? IssueNotificationLevel::All,
        ];

        if ($user->isModOrAdmin()) {
            $preferences['email_moderation_notifications_enabled'] = $this->emailModerationNotificationsEnabled;
        }

        if ($user->isAdmin()) {
            $preferences['email_alt_alert_notifications_enabled'] = $this->emailAltAlertNotificationsEnabled;
        }

        $user->update($preferences);

        Flux::toast(heading: 'Preferences Saved', text: 'Your notification preferences have been saved.', variant: 'success');
    }
};
