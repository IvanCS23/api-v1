<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

class InvoiceCannotBeCancelledException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice, public readonly string $reason)
    {
        parent::__construct(sprintf(
            'La factura [%d] no puede solicitar cancelación fiscal: %s.',
            $invoice->id,
            $reason,
        ));
    }
}
