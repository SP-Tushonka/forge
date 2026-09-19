<?php

declare(strict_types=1);

namespace App\Actions\ModIssues;

use App\Enums\ModIssueEventType;
use App\Enums\ModIssueType;
use App\Models\ModIssue;
use App\Models\User;
use App\Rules\Semver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class UpdateModIssueDetails
{
    /**
     * @throws ValidationException
     */
    public function execute(ModIssue $issue, User $actor, ModIssueType $type, ?string $fixedVersion): void
    {
        // Stored the way ModVersion stores versions, so the release sweep can match the two strings exactly.
        $fixedVersion = mb_ltrim(mb_trim((string) $fixedVersion), 'vV');
        $fixedVersion = $fixedVersion === '' ? null : $fixedVersion;

        Validator::make(
            ['fixedVersion' => $fixedVersion],
            ['fixedVersion' => ['nullable', 'string', 'max:50', new Semver]],
        )->validate();

        DB::transaction(function () use ($issue, $actor, $type, $fixedVersion): void {
            if ($issue->type !== $type) {
                $issue->recordEvent(ModIssueEventType::TypeChanged, $actor, $issue->type->value, $type->value);
                $issue->type = $type;
            }

            if ($issue->fixed_version !== $fixedVersion) {
                $issue->recordEvent(ModIssueEventType::FixedVersionChanged, $actor, $issue->fixed_version, $fixedVersion);
                $issue->fixed_version = $fixedVersion;
                $issue->fix_notified_at = null;
            }

            if ($issue->isDirty()) {
                $issue->last_activity_at = now();
                $issue->save();
            }
        });
    }
}
