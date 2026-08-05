<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\DownloadInvoiceArtifactsService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 6.3 — Descarga (una sola vez, salvo que ya se haya hecho antes)
 * el XML/PDF REALES de un CFDI ya timbrado en Facturapi TEST y los
 * almacena de forma privada. Nunca expone el contenido de los archivos
 * en la salida del comando — solo metadatos sanitizados.
 */
class FacturapiTestDownloadArtifacts extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-test-artifacts {invoice : ID de la Invoice ya timbrada cuyos artifacts se descargarán}';

    /**
     * @var string
     */
    protected $description = 'Descarga y almacena de forma privada el XML/PDF reales de un CFDI ya timbrado en Facturapi TEST.';

    public function handle(DownloadInvoiceArtifactsService $downloadArtifacts): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando está prohibido en production. Solo se ejecuta en entornos local/testing.');

            return self::FAILURE;
        }

        if (blank(config('services.facturapi.test_key'))) {
            $this->error('FACTURAPI_TEST_KEY no está configurada. Configúrala (solo la llave de TEST, nunca una llave live) antes de continuar.');

            return self::FAILURE;
        }

        $invoiceId = (int) $this->argument('invoice');

        $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->find($invoiceId);

        if ($invoice === null) {
            $this->error("No existe ninguna Invoice con ID [{$invoiceId}].");

            return self::FAILURE;
        }

        if ($invoice->pac_status !== 'valid' || $invoice->cfdi_uuid === null || $invoice->pac_external_id === null) {
            $this->error("La Invoice [{$invoiceId}] no es un CFDI timbrado válido todavía (pac_status debe ser \"valid\", con cfdi_uuid y pac_external_id). Usa billing:facturapi-test-stamp primero.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Esta operación DESCARGARÁ el XML y el PDF reales de este CFDI desde Facturapi TEST y los guardará de forma privada.');
        $this->newLine();

        if (! $this->confirm("¿Confirmas que quieres descargar los artifacts de la Invoice [{$invoiceId}] desde Facturapi TEST?", false)) {
            $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

            return self::SUCCESS;
        }

        app(CurrentTenant::class)->set($invoice->company_id);

        try {
            $result = $downloadArtifacts->download($invoice);
        } catch (Throwable $e) {
            $this->error('No se pudieron obtener los artifacts: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $fresh = $invoice->fresh();

        $this->newLine();
        $this->info('Artifacts obtenidos correctamente.');

        $this->table(
            ['Invoice local', 'Folio', 'UUID', 'XML guardado', 'PDF guardado', 'XML bytes', 'PDF bytes', 'XML sha256', 'PDF sha256', 'Descargado'],
            [[
                $fresh->id,
                $fresh->folio,
                $this->maskUuid($fresh->cfdi_uuid),
                $fresh->cfdi_xml_path !== null ? 'sí' : 'no',
                $fresh->cfdi_pdf_path !== null ? 'sí' : 'no',
                $result->xmlSize,
                $result->pdfSize,
                mb_substr($result->xmlHash, 0, 12).'…',
                mb_substr($result->pdfHash, 0, 12).'…',
                $result->downloadedAt->toDateTimeString(),
            ]],
        );

        return self::SUCCESS;
    }

    private function maskUuid(?string $uuid): string
    {
        if ($uuid === null) {
            return 'null';
        }

        return mb_substr($uuid, 0, 8).'…'.mb_substr($uuid, -4);
    }
}
