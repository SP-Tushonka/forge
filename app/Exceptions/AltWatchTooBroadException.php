<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\DataTransferObjects\AltWatchPreview;
use RuntimeException;

final class AltWatchTooBroadException extends RuntimeException
{
    public static function matching(AltWatchPreview $preview): self
    {
        return new self($preview->truncated
            ? sprintf('The watch finds more candidate accounts than can be checked; %d of those checked match its identifiers.', $preview->count())
            : sprintf('The watch matches %d accounts today, more than %d.', $preview->count(), AltWatchPreview::MAX_MATCHES));
    }
}
