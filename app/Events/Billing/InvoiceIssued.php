<?php

namespace App\Events\Billing;

use App\Data\Billing\PacInvoiceResult;
use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emitido únicamente después de que IssueInvoiceService persiste con
 * éxito (dentro de su propia transacción) el resultado de un timbrado
 * ante el PAC. Fase 6.2: sin listeners todavía — deja preparado el
 * punto de extensión (correo, PDF, XML, reconciliación, etc. quedan
 * fuera de alcance de este sprint).
 */
class InvoiceIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly PacInvoiceResult $result,
    ) {}
}
