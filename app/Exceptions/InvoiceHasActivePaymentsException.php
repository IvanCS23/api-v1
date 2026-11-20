<?php

namespace App\Exceptions;

use App\Models\Invoice;

/**
 * Fase 6.35 (ajuste 4): una factura PPD con REP vigentes no se cancela. Para
 * el SAT un CFDI con documentos relacionados vigentes es "No cancelable";
 * primero se cancelan sus complementos de pago. Un REP con la cancelación
 * pendiente o rechazada sigue vigente (ajuste 1).
 */
class InvoiceHasActivePaymentsException extends InvoiceCannotBeCancelledException
{
    public const CODE = 'INVOICE_HAS_ACTIVE_PAYMENTS';

    public function __construct(Invoice $invoice)
    {
        parent::__construct($invoice, 'tiene complementos de pago vigentes');
    }
}
