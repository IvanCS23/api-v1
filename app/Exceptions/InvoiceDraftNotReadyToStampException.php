<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Se lanza cuando StampPacDraftInvoiceService intenta timbrar un
 * borrador (Fase 6.2.5) cuyo estado remoto RECIÉN sincronizado no
 * cumple `pac_draft_status === 'draft' && pac_draft_ready_to_stamp ===
 * true`. Nunca se basa en el valor local antiguo sin antes sincronizar
 * — ver SyncPacDraftInvoiceService, invocado siempre antes de esta
 * comprobación.
 */
class InvoiceDraftNotReadyToStampException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice)
    {
        parent::__construct(sprintf(
            'La factura [%d] no está lista para timbrarse (pac_draft_status: "%s", pac_draft_ready_to_stamp: %s).',
            $invoice->id,
            $invoice->pac_draft_status ?? 'null',
            $invoice->pac_draft_ready_to_stamp === null ? 'null' : ($invoice->pac_draft_ready_to_stamp ? 'true' : 'false'),
        ));
    }
}
