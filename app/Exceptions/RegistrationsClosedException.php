<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

final class RegistrationsClosedException extends Exception
{
    public function __construct()
    {
        parent::__construct('New account registration is currently disabled.');
    }
}
