@blaze

@props(['comment', 'permissions', 'manager', 'showRepliesToggle' => false])

<div class="mt-4 flex items-center gap-6 text-slate-400">
    @if (!$comment->isDeleted())
        <x-reaction-bar
            reactable-type="comment"
            :reactable-id="$comment->id"
            :counts="$manager->reactionSummary->countsFor($comment->id)"
            :mine="$manager->reactionSummary->mineFor($comment->id)"
            :whitelist="$manager->reactionWhitelist"
            :can-react="$permissions->can($comment->id, 'react')"
        />

        @if ($permissions->can($comment->id, 'update'))
            <button
                type="button"
                wire:click="toggleEditForm({{ $comment->id }})"
                data-test="edit-button-{{ $comment->id }}"
                class="cursor-pointer text-xs hover:underline"
            >
                {{ __('Edit') }}
            </button>
        @endif

        @if ($permissions->can($comment->id, 'delete'))
            <button
                type="button"
                wire:click="confirmDeleteComment({{ $comment->id }})"
                data-test="delete-button-{{ $comment->id }}"
                class="cursor-pointer text-xs text-red-500 hover:text-red-700 hover:underline"
            >
                {{ __('Remove') }}
            </button>
        @endif

        <livewire:report-component
            wire:key="report-{{ $comment->id }}"
            variant="comment"
            :reportable-id="$comment->id"
            :reportable-type="get_class($comment)"
        />

        @verified
            @if (CachedGate::allows('create', [App\Models\Comment::class, $comment->commentable, $comment]))
                <button
                    type="button"
                    wire:click="toggleReplyForm({{ $comment->id }})"
                    data-test="reply-button-{{ $comment->id }}"
                    class="cursor-pointer text-xs hover:underline"
                >
                    {{ __('Reply') }}
                </button>
            @endif
        @endverified
    @endif

    @if ($showRepliesToggle && $manager->getDescendantCount($comment->id) > 0)
        <button
            type="button"
            @click="toggleExpanded()"
            data-test="toggle-replies-{{ $comment->id }}"
            class="cursor-pointer text-xs hover:underline"
        >
            <span x-text="isExpanded ? 'Hide' : 'Show'">Hide</span> Replies
            ({{ $manager->getDescendantCount($comment->id) }})
        </button>
    @endif
</div>
