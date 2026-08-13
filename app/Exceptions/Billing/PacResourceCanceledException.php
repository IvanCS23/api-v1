<?php

namespace App\Exceptions\Billing;

use RuntimeException;

class PacResourceCanceledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El recurso fiscal remoto está cancelado y no puede timbrarse.');
    }
}
