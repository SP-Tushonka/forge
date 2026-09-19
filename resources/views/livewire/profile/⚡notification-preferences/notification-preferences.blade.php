<x-action-section>
    <x-slot name="title">
        {{ __('Notification Preferences') }}
    </x-slot>

    <x-slot name="description">
        {{ __('Manage your notification preferences for comments, chats, and other activities.') }}
    </x-slot>

    <x-slot name="content">
        <flux:field>
            <flux:checkbox.group label="{{ __('Email Notifications') }}">
                <flux:checkbox
                    wire:model.live="emailAnnouncementNotificationsEnabled"
                    wire:change="updateNotificationPreferences"
                    label="{{ __('Announcement Notifications') }}"
                    description="{{ __('Receive email notifications about important site announcements such as policy updates.') }}"
                />
                <flux:checkbox
                    wire:model.live="emailCommentNotificationsEnabled"
                    wire:change="updateNotificationPreferences"
                    label="{{ __('Comment Notifications') }}"
                    description="{{ __('Receive email notifications when someone comments on content you are subscribed to.') }}"
                />
                <flux:checkbox
                    wire:model.live="emailReplyNotificationsEnabled"
                    wire:change="updateNotificationPreferences"
                    label="{{ __('Reply Notifications') }}"
                    description="{{ __('Receive email notifications when someone replies directly to your comments.') }}"
                />
                <flux:checkbox
                    wire:model.live="emailChatNotificationsEnabled"
                    wire:change="updateNotificationPreferences"
                    label="{{ __('Chat Notifications') }}"
                    description="{{ __('Receive email notifications when you have unread chat messages.') }}"
                />
                @if (auth()->user()?->isModOrAdmin())
                    <flux:checkbox
                        wire:model.live="emailModerationNotificationsEnabled"
                        wire:change="updateNotificationPreferences"
                        label="{{ __('Moderation Notifications') }}"
                        description="{{ __('Receive email notifications for moderation activity, such as new content reports.') }}"
                    />
                @endif
            </flux:checkbox.group>
        </flux:field>

        <flux:radio.group
            wire:model.live="issueNotifications"
            :label="__('Issue Notifications')"
            :description="__('New issues on your mods, and status changes, comments and released fixes on issues you follow. You can also mute one issue, or a whole mod, from its page.')"
            class="mt-6"
        >
            @foreach (App\Enums\IssueNotificationLevel::cases() as $level)
                <flux:radio
                    value="{{ $level->value }}"
                    :label="$level->label()"
                />
            @endforeach
        </flux:radio.group>
    </x-slot>
</x-action-section>
