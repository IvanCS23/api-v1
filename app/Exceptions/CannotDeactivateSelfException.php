<?php

namespace App\Exceptions;

use RuntimeException;

class CannotDeactivateSelfException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No puedes desactivar tu propia cuenta.');
    }
}
