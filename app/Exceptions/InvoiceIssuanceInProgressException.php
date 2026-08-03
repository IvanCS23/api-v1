<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Se lanza cuando IssueInvoiceService, ya bajo lockForUpdate() dentro de
 * la transacción corta de reserva, encuentra que la Invoice ya tiene una
 * emisión en curso: `pac_issue_status` en `pending` (otra llamada, propia
 * o concurrente, ya reservó esta emisión y aún no terminó) o en
 * `reconciliation_required` (una llamada anterior tuvo una respuesta
 * ambigua — timeout/conexión interrumpida/respuesta no parseable — y no
 * se sabe con certeza si el PAC llegó a crear la factura).
 *
 * En ambos casos, nunca se hace una segunda llamada HTTP al PAC con la
 * misma Invoice: `reconciliation_required` solo se resuelve mediante
 * ReconcileInvoiceWithPacService, nunca reintentando la emisión con una
 * llave distinta (arriesgaría una segunda factura real ante el PAC).
 */
class InvoiceIssuanceInProgressException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct(sprintf(
            'La factura [%d] ya tiene una emisión en curso ante el PAC (pac_issue_status: "%s"); no se realiza una segunda llamada.',
            $invoice->id,
            $invoice->pac_issue_status ?? 'unknown',
        ));
    }
}
