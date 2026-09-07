<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A staff action refused to run. The message is shown verbatim to the staff member.
 */
final class StaffActionException extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
