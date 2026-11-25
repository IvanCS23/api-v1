<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

class InvoiceNotEditableException extends RuntimeException
{
    public static function forUpdate(Invoice $invoice): self
    {
        return new self('Esta factura ya no se puede editar en su estado actual.');
    }
}
