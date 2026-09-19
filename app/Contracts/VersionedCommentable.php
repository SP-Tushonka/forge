<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\VersionChange;
use App\Enums\VersionTagColor;

/**
 * A commentable that ships versions. New comments are stamped with its latest public version, so readers can tell when
 * a comment was written for an older release.
 */
interface VersionedCommentable
{
    /**
     * The highest version the public can see, or null when there is none.
     */
    public function getCommentableVersion(): ?string;

    /**
     * The tag colour for a comment whose stamped version differs from the latest by this much.
     */
    public function getCommentVersionTagColor(VersionChange $change): VersionTagColor;
}
