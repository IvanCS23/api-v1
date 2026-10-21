<?php

namespace App\Listeners\Billing;

use App\Events\Billing\InvoiceIssued;
use App\Jobs\Billing\DownloadInvoiceArtifactsJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Encola la descarga del XML/PDF cuando un CFDI queda timbrado
 * (Fase 6.21). InvoiceIssued se emite después del commit, así que el job
 * nunca ve una emisión sin persistir.
 *
 * Nunca altera el resultado del timbrado: cualquier error al encolar
 * (o, con la cola `sync`, al ejecutar el job en línea) se registra y se
 * descarta. El botón "Obtener XML/PDF" y billing:health siguen cubriendo
 * los artifacts faltantes.
 */
class QueueInvoiceArtifactsDownload
{
    public function handle(InvoiceIssued $event): void
    {
        if (! config('billing.artifacts.auto_download')) {
            return;
        }

        try {
            DownloadInvoiceArtifactsJob::dispatch((int) $event->invoice->company_id, (int) $event->invoice->id);
        } catch (Throwable $e) {
            Log::warning('billing.invoice.artifacts_auto_download_not_queued', [
                'invoice_id' => $event->invoice->id,
                'company_id' => $event->invoice->company_id,
                'reason' => $e::class,
            ]);
        }
    }
}
