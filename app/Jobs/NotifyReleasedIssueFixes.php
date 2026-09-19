<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ModIssueEventType;
use App\Enums\ModIssueStatus;
use App\Models\ModIssue;
use App\Models\ModVersion;
use App\Models\Scopes\PublishedScope;
use App\Notifications\ModIssueFixReleasedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

/**
 * Tells followers when the version a fix was promised for goes public. This is a sweep, not a ModVersion observer:
 * scheduled versions go public with no event to hook, and an issue can be marked Completed after its version shipped.
 */
final class NotifyReleasedIssueFixes implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        // By id, not offset: announcing an issue removes it from this very result set.
        ModIssue::query()
            ->where('status', ModIssueStatus::Completed)
            ->whereNotNull('fixed_version')
            ->whereNull('fix_notified_at')
            ->with('mod')
            ->eachById(function (ModIssue $issue): void {
                $this->announce($issue);
            }, 100);
    }

    private function announce(ModIssue $issue): void
    {
        if ($issue->mod->disabled || ! $issue->mod->isPublished()) {
            return;
        }

        $released = ModVersion::query()
            ->withoutGlobalScope(PublishedScope::class)
            ->where('mod_id', $issue->mod_id)
            ->where('version', $issue->fixed_version)
            ->where(function (Builder $query): void {
                $query->publiclyVisible()->orWhere(function (Builder $legacy): void {
                    $legacy->legacyPubliclyVisible();
                });
            })
            ->exists();

        if (! $released) {
            return;
        }

        // Claimed atomically, so an overlapping run or a retry cannot send the notice twice.
        $claimed = ModIssue::query()
            ->whereKey($issue->id)
            ->whereNull('fix_notified_at')
            ->update(['fix_notified_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $version = (string) $issue->fixed_version;

        $issue->recordEvent(ModIssueEventType::FixReleased, null, null, $version);

        Notification::send($issue->getSubscribers(), new ModIssueFixReleasedNotification($issue, $version));
    }
}
