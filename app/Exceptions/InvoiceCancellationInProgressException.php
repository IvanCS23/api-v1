<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Otra ejecución ya reservó la solicitud de cancelación ante el PAC
 * (`pac_cancel_request_status=in_flight`); no se envía un segundo DELETE.
 */
class InvoiceCancellationInProgressException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct(sprintf(
            'La factura [%d] ya tiene una solicitud de cancelación en curso ante el PAC; no se realiza una segunda llamada.',
            $invoice->id,
        ));
    }
}
