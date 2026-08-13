<?php

namespace App\Exceptions\Billing;

use RuntimeException;

class InvoiceArtifactIntegrityException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El artifact fiscal no supera la validación de integridad.');
    }
}
