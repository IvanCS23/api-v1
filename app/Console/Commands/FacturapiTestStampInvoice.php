<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\StampPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 6.2.5 — El comando de mayor consecuencia de todo el proyecto:
 * TIMBRA de verdad un borrador ya existente en Facturapi TEST. El
 * recurso remoto deja de ser un draft y se convierte en un CFDI real
 * (de prueba, pero con un UUID/timbre reales del SAT de pruebas).
 *
 * NUNCA usa `createInvoice()` ni crea un CFDI nuevo — timbra
 * exclusivamente el borrador ya identificado por
 * `pac_draft_external_id`.
 */
class FacturapiTestStampInvoice extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-test-stamp {invoice : ID de la Invoice cuyo borrador se timbrará}';

    /**
     * @var string
     */
    protected $description = 'TIMBRA un borrador real en Facturapi TEST (lo convierte en CFDI). Operación irreversible en el PAC.';

    public function handle(StampPacDraftInvoiceService $stampDraft): int
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
        $this->error('⚠⚠⚠ ADVERTENCIA ⚠⚠⚠');
        $this->warn('Esta operación TIMBRARÁ el borrador en Facturapi TEST y lo enviará al SAT de pruebas.');
        $this->warn('El recurso dejará de ser un draft.');
        $this->newLine();

        if (! $this->confirm("¿Confirmas que quieres TIMBRAR el borrador de la Invoice [{$invoiceId}] en Facturapi TEST?", false)) {
            $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

            return self::SUCCESS;
        }

        app(CurrentTenant::class)->set($invoice->company_id);

        try {
            $updated = $stampDraft->stamp($invoice);
        } catch (Throwable $e) {
            $this->error('No se pudo timbrar el borrador: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $this->newLine();

        if ($updated->pac_status === 'valid' && $updated->cfdi_uuid !== null) {
            $this->info('Borrador timbrado correctamente — la factura ahora tiene un CFDI real (TEST).');
        } else {
            $this->warn('El PAC respondió, pero el timbrado aún no está confirmado como válido (status: '.($updated->pac_status ?? 'desconocido').'). Revisa con billing:facturapi-test-draft-sync más adelante.');
        }

        $this->table(
            ['Invoice local', 'Pac invoice id', 'Status', 'UUID', 'stamped_at'],
            [[
                $updated->id,
                $updated->pac_external_id,
                $updated->pac_status,
                $this->maskUuid($updated->cfdi_uuid),
                optional($updated->stamped_at)->toDateTimeString(),
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
