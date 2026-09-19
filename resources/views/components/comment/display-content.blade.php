@blaze

@props(['comment', 'permissions', 'manager', 'isReply' => false, 'commentable' => null])

<x-comment.card
    :comment="$comment"
    :anchor-id="$manager->getCommentHashId($comment->id)"
>
    <x-slot:headerTrailing>
        @if (($comment->parent_id && $comment->parent) || $comment->commentable_version !== null)
            {{-- The action menu is absolutely positioned, so mr-10 keeps these clear of the gear. --}}
            <div @class([
                'ml-2 flex min-w-0 flex-wrap items-center justify-end gap-2',
                'mr-10' => $permissions->can($comment->id, 'viewActions'),
            ])>
                @if ($comment->parent_id && $comment->parent)
                    <a
                        href="#{{ $manager->getCommentHashId($comment->parent_id) }}"
                        class="text-xs text-slate-400 underline hover:text-cyan-400 [&_span]:underline"
                    >
                        {{ __('Replying to') }} @<x-user-name :user="$comment->parent->user" />
                    </a>
                @endif
                @if ($comment->commentable_version !== null)
                    <x-comment.version-tag
                        :comment="$comment"
                        :latest="$manager->latestCommentableVersion"
                        :color="$manager->commentVersionTagColor($comment->commentable_version)"
                    />
                @endif
            </div>
        @endif
        @if ($permissions->can($comment->id, 'viewActions'))
            <x-comment.action-menu
                :comment="$comment"
                :permissions="$permissions"
                :descendants-count="$comment->isRoot() ? $manager->getDescendantCount($comment->id) : null"
            />
        @endif
    </x-slot>

    <x-comment.actions
        :comment="$comment"
        :permissions="$permissions"
        :manager="$manager"
        :show-replies-toggle="$comment->isRoot()"
    />

    {{-- Reply Form --}}
    @if (
        $manager->isFormVisible('reply', $comment->id) &&
            CachedGate::allows('create', [App\Models\Comment::class, $comment->commentable, $comment]))
        <div class="mt-4">
            <flux:separator text="Reply To Comment" />
            <div class="mt-2.5">
                <x-comment.form
                    form-key="formStates.reply-{{ $comment->id }}.body"
                    data-test="reply-body-{{ $comment->id }}"
                    submit-action="createReply({{ $comment->id }})"
                    submit-text="{{ __('Post Reply') }}"
                    cancel-action="toggleReplyForm({{ $comment->id }})"
                />
            </div>
        </div>
    @endif

    {{-- Edit Form --}}
    @if ($manager->isFormVisible('edit', $comment->id))
        <div class="mt-4">
            <flux:separator text="Edit Comment" />
            <div class="mt-2.5">
                <x-comment.form
                    form-key="formStates.edit-{{ $comment->id }}.body"
                    data-test="edit-body-{{ $comment->id }}"
                    submit-action="updateComment({{ $comment->id }})"
                    submit-text="{{ __('Update Comment') }}"
                    cancel-action="toggleEditForm({{ $comment->id }})"
                />
            </div>
        </div>
    @endif
</x-comment.card>
