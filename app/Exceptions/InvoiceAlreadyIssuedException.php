<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Se lanza cuando IssueInvoiceService detecta que la Invoice ya tiene
 * `cfdi_uuid` o `pac_external_id` (ya fue emitida ante el PAC). Cubre
 * tanto un doble intento secuencial como dos llamadas concurrentes que
 * pasaron ambas la validación inicial antes de que cualquiera adquiriera
 * el lock — mismo patrón que SaleAlreadyInvoicedException/
 * QuoteAlreadyConvertedException. Nunca reenvía la factura al PAC.
 */
class InvoiceAlreadyIssuedException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct(sprintf(
            'La factura [%d] ya fue emitida ante el PAC (cfdi_uuid: %s, pac_external_id: %s).',
            $invoice->id,
            $invoice->cfdi_uuid ?? 'null',
            $invoice->pac_external_id ?? 'null',
        ));
    }
}
