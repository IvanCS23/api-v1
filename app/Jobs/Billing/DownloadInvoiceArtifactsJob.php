<?php

namespace App\Jobs\Billing;

use App\Models\Invoice;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Services\Billing\InvoiceBusinessCapabilitiesService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Descarga automática del XML/PDF tras un timbrado exitoso (Fase 6.21).
 *
 * Recibe ids, nunca el modelo: un worker no tiene tenant activo y
 * SerializesModels reconstruiría la Invoice a través de CompanyScope. El
 * job fija el tenant de la factura, delega en
 * DownloadInvoiceArtifactsService (reserva bajo lock, validación de UUID,
 * escritura atómica) y restaura el tenant previo, porque un worker
 * reutiliza el mismo proceso entre jobs.
 *
 * Nunca toca la emisión: el servicio sólo escribe `cfdi_artifacts_*`.
 * Un fallo definitivo (`failed`) no se reintenta; uno ambiguo sí, con
 * backoff.
 */
class DownloadInvoiceArtifactsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $companyId,
        public readonly int $invoiceId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->companyId}:{$this->invoiceId}";
    }

    public function handle(
        DownloadInvoiceArtifactsService $artifacts,
        InvoiceBusinessCapabilitiesService $capabilities,
        CurrentTenant $tenant,
    ): void {
        $previousTenant = $tenant->id();
        $tenant->set($this->companyId);

        try {
            $invoice = Invoice::query()->find($this->invoiceId);

            // Ya almacenados, otra descarga vigente, o la factura dejó de
            // ser descargable: no hay nada que hacer.
            if ($invoice === null || ! $capabilities->artifactsFetchable($invoice)) {
                return;
            }

            try {
                $artifacts->download($invoice);
            } catch (Throwable $e) {
                Log::warning('billing.invoice.artifacts_auto_download_failed', [
                    'invoice_id' => $this->invoiceId,
                    'company_id' => $this->companyId,
                    'attempt' => $this->attempts(),
                    'reason' => $e::class,
                ]);

                if ($invoice->fresh()?->cfdi_artifacts_status === 'failed') {
                    $this->fail($e);

                    return;
                }

                throw $e;
            }
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }
}
