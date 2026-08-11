<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class AccountNotArchivedException extends Exception
{
    public function __construct()
    {
        parent::__construct('That account is no longer awaiting recovery.');
    }
}
