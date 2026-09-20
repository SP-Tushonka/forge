<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\ModIssueStatus;
use App\Enums\ModIssueType;
use App\Enums\TrackingEventType;
use App\Facades\Track;
use App\Models\Mod;
use App\Models\ModIssue;
use App\Models\User;
use App\Notifications\NewModIssueNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

final readonly class CreateModIssue
{
    /**
     * @throws ValidationException
     */
    public function execute(User $reporter, Mod $mod, ModIssueType $type, string $title, string $body, ?int $affectedVersionId): ModIssue
    {
        if (! $mod->allowsIssueType($type)) {
            throw ValidationException::withMessages([
                'type' => __('This mod is not accepting that kind of issue.'),
            ]);
        }

        // ModIssue's creating hook bumps the mod's issue counter. Inside the transaction that increment holds the mod
        // row lock until the insert commits, so a concurrent report waits instead of reading the same number.
        $issue = DB::transaction(function () use ($reporter, $mod, $type, $title, $body, $affectedVersionId): ModIssue {
            return ModIssue::query()->create([
                'mod_id' => $mod->id,
                'user_id' => $reporter->id,
                'type' => $type,
                'status' => ModIssueStatus::New,
                'title' => $title,
                'body' => $body,
                'affected_mod_version_id' => $type->showsAffectedVersion() ? $affectedVersionId : null,
                'last_activity_at' => now(),
            ]);
        });

        $issue->setRelation('mod', $mod);
        $issue->setRelation('user', $reporter);

        $managers = $mod->managers();

        foreach ($managers->concat([$reporter])->unique('id') as $user) {
            $issue->subscribeUnlessMuted($user);
        }

        Notification::send(
            $issue->withoutOptedOut($managers->reject(fn (User $user): bool => $user->id === $reporter->id)->values()),
            new NewModIssueNotification($issue),
        );

        Track::event(TrackingEventType::ISSUE_CREATE, $issue);

        return $issue;
    }
}
