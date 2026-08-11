<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

final class EmailAlreadyRegisteredException extends Exception
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('That email address already belongs to another account.', previous: $previous);
    }
}
