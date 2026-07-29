<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Se lanza cuando IssueInvoiceService intenta emitir ante el PAC una
 * Invoice que no está en estado `Issued` (único estado interno elegible
 * para timbrado — ver ERP_ARCHITECTURE_PLAN.md: Issued es la transición
 * terminal e inmutable del dominio interno, análoga a "Confirmed" en
 * otros agregados). No modifica ningún estado; solo bloquea la emisión.
 */
class InvoiceCannotBeIssuedException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct(sprintf(
            'La factura [%d] no puede emitirse ante el PAC en su estado actual ("%s"); solo facturas en estado "issued" son elegibles.',
            $invoice->id,
            $invoice->status->value,
        ));
    }
}
