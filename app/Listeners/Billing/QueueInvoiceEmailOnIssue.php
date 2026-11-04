<?php

namespace App\Listeners\Billing;

use App\Events\Billing\InvoiceIssued;
use App\Jobs\Billing\SendInvoiceEmailJob;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fase 6.30: si la empresa tiene activado el envío automático, encola
 * SendInvoiceEmailJob al timbrarse un CFDI. InvoiceIssued se emite después
 * del commit. Nunca altera el resultado del timbrado: un error al encolar
 * se registra y se descarta (el envío manual sigue disponible).
 */
class QueueInvoiceEmailOnIssue
{
    public function handle(InvoiceIssued $event): void
    {
        try {
            $companyId = (int) $event->invoice->company_id;

            if (Company::query()->find($companyId)?->auto_email_invoices !== true) {
                return;
            }

            SendInvoiceEmailJob::dispatch($companyId, (int) $event->invoice->id);
        } catch (Throwable $e) {
            Log::warning('billing.invoice.email_auto_not_queued', [
                'invoice_id' => $event->invoice->id,
                'company_id' => $event->invoice->company_id,
                'reason' => $e::class,
            ]);
        }
    }
}
