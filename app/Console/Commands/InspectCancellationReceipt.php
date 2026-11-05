<?php

namespace App\Console\Commands;

use App\Exceptions\Billing\PacException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InspectCancellationReceiptXmlService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 6.31 — Diagnóstico del acuse de cancelación, de solo lectura y en
 * cualquier ambiente (también Live y APP_ENV=production), para el primer
 * acuse real: muestra Acuse/Folios con el UUID enmascarado, si corresponde
 * a la factura y su EstatusUUID, más la estructura del XML.
 *
 * Sólo descarga el XML con la llave del ambiente fijado en la factura (pasa
 * por el guard y por el ambiente). Nunca guarda bytes ni metadata, nunca
 * imprime el XML ni el UUID completo y pide confirmación antes del HTTP.
 * No relaja ninguna validación: es para decidir con evidencia.
 */
class InspectCancellationReceipt extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:cancellation-receipt-inspect
        {invoice : ID de la factura cancelada cuyo acuse se inspeccionará}';

    /**
     * @var string
     */
    protected $description = 'Muestra, sin guardar nada, los Folios (UUID enmascarado y EstatusUUID) y la estructura del acuse de cancelación.';

    public function handle(InspectCancellationReceiptXmlService $service, CurrentTenant $tenant): int
    {
        $invoiceId = (int) $this->argument('invoice');
        $locator = Invoice::withoutGlobalScope(CompanyScope::class)->select(['id', 'company_id'])->find($invoiceId);

        if ($locator === null) {
            $this->error("No existe ninguna factura con ID [{$invoiceId}].");

            return self::FAILURE;
        }

        $previousTenant = $tenant->id();
        $tenant->set((int) $locator->company_id);

        try {
            $invoice = Invoice::findOrFail($invoiceId);

            $this->warn('Se descargará SOLO el XML del acuse desde Facturapi (ambiente '.($invoice->pac_environment ?? 'sin fijar').'). No se guarda nada.');

            if (! $this->confirm("¿Confirmas la inspección de solo lectura de la factura [{$invoiceId}]?", false)) {
                $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

                return self::SUCCESS;
            }

            $inspection = $service->inspect($invoice);

            $this->line('root = '.$inspection['root']);
            $this->line('root_namespace = '.$inspection['root_namespace']);
            $this->line('elements = '.json_encode($inspection['elements'], JSON_UNESCAPED_SLASHES));
            $this->table(
                ['folio_uuid', 'corresponde', 'EstatusUUID', 'aceptado (201/202)'],
                array_map(fn (array $folio): array => [$folio['uuid'], $folio['matches_invoice'] ? 'sí' : 'no', $folio['estatus_uuid'], $folio['accepted'] ? 'sí' : 'no'], $inspection['folios']),
            );

            if ($inspection['folios'] === []) {
                $this->warn('El acuse no tiene Acuse/Folios/UUID: la descarga lo rechazaría.');
            }

            $this->info('Diagnóstico terminado: el XML fue descartado sin guardarse.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo inspeccionar el acuse ('.class_basename($e).'). No se guardó nada.');

            if ($e instanceof PacException && $e->pacCode !== null) {
                $this->line('Código PAC: '.$e->pacCode);
            }

            return self::FAILURE;
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }
    }
}
