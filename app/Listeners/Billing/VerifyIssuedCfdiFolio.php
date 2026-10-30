<?php

namespace App\Listeners\Billing;

use App\Enums\InvoicePacEventType;
use App\Events\Billing\InvoiceIssued;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Billing\InvoiceFolio;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 6.28: al quedar timbrado un CFDI, comprueba que la serie y el folio
 * con que Facturapi lo timbró (cfdi_series/cfdi_folio_number, tomados de
 * la respuesta por el modelo) son los del ERP. Facturapi no documenta que
 * rechace un folio distinto ni que respete siempre el enviado.
 *
 * El CFDI ya existe, así que no se bloquea nada: se registra en el
 * historial y en el log para revisarlo. Nunca altera el resultado del
 * timbrado.
 */
class VerifyIssuedCfdiFolio
{
    public function __construct(private readonly InvoicePacAuditService $audit) {}

    public function handle(InvoiceIssued $event): void
    {
        try {
            $invoice = $event->invoice;
            $expected = InvoiceFolio::parse($invoice->folio);
            $received = InvoiceFolio::label($invoice->cfdi_series, $invoice->cfdi_folio_number);

            // Sin folio en la respuesta no hay con qué comparar (lo cubre
            // billing:backfill-cfdi-folio o la siguiente reconciliación).
            if ($expected === null || $received === null || $received === $expected->cfdiLabel()) {
                return;
            }

            $context = ['expected_folio' => $expected->cfdiLabel(), 'received_folio' => $received];

            Log::warning('billing.invoice.cfdi_folio_mismatch', [
                'invoice_id' => $invoice->id,
                'company_id' => $invoice->company_id,
                ...$context,
            ]);

            $this->audit->appendSafely($invoice, InvoicePacEventType::CfdiFolioMismatch, $context);
        } catch (Throwable $e) {
            Log::warning('billing.invoice.cfdi_folio_check_failed', [
                'invoice_id' => $event->invoice->id,
                'reason' => $e::class,
            ]);
        }
    }
}
