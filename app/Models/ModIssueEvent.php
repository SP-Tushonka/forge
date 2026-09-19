<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ModIssueEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $mod_issue_id
 * @property int|null $user_id
 * @property ModIssueEventType $type
 * @property string|null $from
 * @property string|null $to
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ModIssue $issue
 * @property-read User|null $user
 */
final class ModIssueEvent extends Model
{
    /**
     * @return BelongsTo<ModIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(ModIssue::class, 'mod_issue_id')->withTrashed();
    }

    /**
     * Null for an automatic change.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'mod_issue_id' => 'integer',
            'user_id' => 'integer',
            'type' => ModIssueEventType::class,
        ];
    }
}
