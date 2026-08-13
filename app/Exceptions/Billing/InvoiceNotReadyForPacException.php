<?php

namespace App\Exceptions\Billing;

use RuntimeException;

class InvoiceNotReadyForPacException extends RuntimeException
{
    /**
     * @param  list<array{code: string, field: string}>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('La factura no está lista para emisión fiscal.');
    }
}
