<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InvoiceWorkflow;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconciliación manual y explícita contra Facturapi TEST. Del lado del PAC
 * es estrictamente de lectura: nunca emite, timbra, cancela ni descarga.
 */
class FacturapiTestReconcileInvoice extends Command
{
    protected $signature = 'billing:facturapi-test-reconcile {invoice : ID de la Invoice a reconciliar}';

    protected $description = 'Consulta Facturapi TEST y reconcilia únicamente metadata fiscal local de una Invoice existente.';

    public function handle(InvoiceWorkflow $workflow): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando está prohibido en production. Solo se ejecuta en entornos local/testing.');

            return self::FAILURE;
        }

        if (blank(config('services.facturapi.test_key'))) {
            $this->error('FACTURAPI_TEST_KEY no está configurada. Configúrala con una llave de TEST antes de continuar.');

            return self::FAILURE;
        }

        $invoiceId = (int) $this->argument('invoice');
        $locator = Invoice::withoutGlobalScope(CompanyScope::class)
            ->select(['id', 'company_id'])
            ->find($invoiceId);

        if ($locator === null) {
            $this->error("No existe ninguna Invoice con ID [{$invoiceId}].");

            return self::FAILURE;
        }

        app(CurrentTenant::class)->set($locator->company_id);

        try {
            $invoice = Invoice::findOrFail($invoiceId);

            if ($invoice->pac_external_id === null) {
                $this->error("La Invoice [{$invoiceId}] no tiene pac_external_id; no existe una identidad remota inequívoca que consultar.");

                return self::FAILURE;
            }

            $this->newLine();
            $this->warn('Esta operación CONSULTARÁ Facturapi TEST y reconciliará únicamente metadata fiscal local. No emitirá, timbrará ni cancelará CFDIs.');
            $this->newLine();

            if (! $this->confirm("¿Confirmas que quieres reconciliar la Invoice [{$invoiceId}] con Facturapi TEST?", false)) {
                $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

                return self::SUCCESS;
            }

            $updated = $workflow->reconcileWithPac($invoice);

            $this->newLine();
            $this->info('Invoice reconciliada con Facturapi TEST.');
            $this->table(
                ['Invoice local', 'Folio', 'PAC external id', 'UUID', 'Status local', 'Status PAC', 'Cancellation status', 'Reconciliation required', 'Last sync'],
                [[
                    $updated->id,
                    $updated->folio,
                    $this->mask($updated->pac_external_id),
                    $this->maskUuid($updated->cfdi_uuid),
                    $updated->status->value,
                    $updated->pac_status ?? 'null',
                    $updated->cancellation_status ?? 'null',
                    $updated->pac_reconciliation_required ? 'true' : 'false',
                    optional($updated->last_pac_sync_at)->toDateTimeString() ?? 'null',
                ]],
            );

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se pudo reconciliar la Invoice. Revisa pac_last_error y los logs sanitizados para el diagnóstico.');

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    private function mask(?string $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (mb_strlen($value) <= 12) {
            return mb_substr($value, 0, 4).'…';
        }

        return mb_substr($value, 0, 8).'…'.mb_substr($value, -4);
    }

    private function maskUuid(?string $uuid): string
    {
        return $uuid === null ? 'null' : mb_substr($uuid, 0, 8).'…'.mb_substr($uuid, -4);
    }
}
