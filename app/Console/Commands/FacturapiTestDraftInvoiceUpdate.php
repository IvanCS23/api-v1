<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\UpdatePacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 6.2.7 — Actualiza (PUT) el borrador REAL ya existente en
 * Facturapi TEST con el snapshot fiscal actual de la Invoice. Nunca
 * crea otro recurso (usa el mismo `pac_draft_external_id`) y nunca
 * timbra.
 */
class FacturapiTestDraftInvoiceUpdate extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-test-draft-update {invoice : ID de la Invoice cuyo borrador remoto se actualizará}';

    /**
     * @var string
     */
    protected $description = 'Actualiza (PUT) el borrador REAL ya existente en Facturapi TEST con el snapshot fiscal actual. Nunca crea otro recurso y nunca timbra.';

    public function handle(UpdatePacDraftInvoiceService $updateDraft): int
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

        if ($invoice->pac_draft_external_id === null) {
            $this->error("La Invoice [{$invoiceId}] no tiene un borrador remoto registrado. Usa billing:facturapi-test-draft primero.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->warn('Esta operación ACTUALIZARÁ el draft existente en Facturapi TEST.');
        $this->warn('No creará otro recurso y no timbrará.');
        $this->newLine();

        if (! $this->confirm("¿Confirmas que quieres ACTUALIZAR el borrador de la Invoice [{$invoiceId}] en Facturapi TEST?", false)) {
            $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

            return self::SUCCESS;
        }

        app(CurrentTenant::class)->set($invoice->company_id);

        try {
            $updated = $updateDraft->update($invoice);
        } catch (Throwable $e) {
            $this->error('No se pudo actualizar el borrador: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $this->newLine();

        if ($updated->cfdi_uuid !== null) {
            $this->warn('El borrador ya estaba timbrado (por esta integración o fuera de ella) — se reconcilió el estado local. No se envió ninguna actualización de payload.');
        } elseif ($updated->pac_draft_status === 'draft') {
            $this->info('Borrador actualizado correctamente.');
        } else {
            $this->warn('El borrador no se actualizó: su estado remoto no es "draft" (status: '.($updated->pac_draft_status ?? 'desconocido').').');
        }

        $remoteTotal = $updated->pac_response['total'] ?? $updated->pac_draft_response['total'] ?? null;
        $localTotal = (float) $updated->total;

        if ($remoteTotal !== null && is_numeric($remoteTotal) && abs(((float) $remoteTotal) - $localTotal) >= 0.005) {
            $this->newLine();
            $this->error('⚠ El total remoto no coincide con el total local. Revisa antes de continuar — no se ejecutó ningún timbrado.');
        }

        $this->table(
            ['Invoice local', 'Pac draft id', 'Status', 'is_ready_to_stamp', 'Total remoto', 'Última sincronización'],
            [[
                $updated->id,
                $updated->pac_draft_external_id,
                $updated->pac_draft_status,
                $updated->pac_draft_ready_to_stamp === null ? 'null' : ($updated->pac_draft_ready_to_stamp ? 'true' : 'false'),
                $remoteTotal ?? 'desconocido',
                optional($updated->pac_draft_last_sync_at)->toDateTimeString(),
            ]],
        );

        return self::SUCCESS;
    }
}
