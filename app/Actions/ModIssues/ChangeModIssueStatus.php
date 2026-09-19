<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\ModIssueEventType;
use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use App\Models\User;
use App\Notifications\ModIssueStatusChangedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final readonly class ChangeModIssueStatus
{
    /**
     * A null actor marks an automatic change. It is logged without a name and sends no notification, because whatever
     * triggered it has already notified subscribers.
     *
     * @throws ValidationException
     */
    public function execute(ModIssue $issue, ModIssueStatus $to, ?User $actor, ?ModIssue $duplicateOf = null): void
    {
        $from = $issue->status;
        $duplicateOfId = $to === ModIssueStatus::Duplicate ? $this->validDuplicateId($issue, $duplicateOf) : null;

        if ($from === $to && $issue->duplicate_of_id === $duplicateOfId) {
            return;
        }

        $reopened = ! $from->isOpen() && $to->isOpen();

        DB::transaction(function () use ($issue, $from, $to, $actor, $duplicateOfId, $reopened): void {
            $issue->status = $to;
            $issue->duplicate_of_id = $duplicateOfId;
            $issue->last_activity_at = now();

            if ($from->isOpen() && ! $to->isOpen()) {
                $issue->closed_at = now();
                $issue->closed_by = $actor?->id;
            }

            if ($reopened) {
                $issue->closed_at = null;
                $issue->closed_by = null;
            }

            $issue->save();

            if ($reopened) {
                $issue->recordEvent(ModIssueEventType::Reopened, $actor);
            }

            if ($from !== $to) {
                $issue->recordEvent(ModIssueEventType::StatusChanged, $actor, $from->value, $to->value);
            }
        });

        if (! $actor instanceof User || $from === $to) {
            return;
        }

        Notification::send(
            $issue->getSubscribers()->reject(fn (User $user): bool => $user->id === $actor->id)->values(),
            new ModIssueStatusChangedNotification($issue, $from, $to, $actor),
        );
    }

    /**
     * @throws ValidationException
     */
    private function validDuplicateId(ModIssue $issue, ?ModIssue $duplicateOf): int
    {
        if (! $duplicateOf instanceof ModIssue
            || $duplicateOf->id === $issue->id
            || $duplicateOf->mod_id !== $issue->mod_id
            || $duplicateOf->trashed()) {
            throw ValidationException::withMessages([
                'duplicateOfId' => __('Choose another issue on this mod.'),
            ]);
        }

        return $duplicateOf->id;
    }
}
