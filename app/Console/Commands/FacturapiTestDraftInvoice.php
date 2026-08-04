<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\CreatePacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Fase 6.2.4 — Primer comando que SÍ realiza una llamada HTTP real
 * (cuando un humano lo ejecuta manualmente, nunca durante esta tarea):
 * crea un borrador (`status: "draft"`) real en Facturapi TEST.
 *
 * Un borrador se PERSISTE de verdad del lado de Facturapi — no es una
 * simulación. NUNCA se timbra desde aquí, nunca tiene UUID/validez
 * fiscal, y nunca usa IssueInvoiceService. Exige confirmación explícita
 * precisamente porque, a diferencia de `billing:facturapi-test-validate`
 * (Fase 6.2.3, puramente local), esta operación SÍ tiene un efecto real
 * del lado del PAC.
 */
class FacturapiTestDraftInvoice extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-test-draft {invoice : ID de la Invoice para la que se creará el borrador}';

    /**
     * @var string
     */
    protected $description = 'Crea (o sincroniza si ya existe) un borrador REAL en Facturapi TEST para una Invoice. Nunca timbra.';

    public function handle(CreatePacDraftInvoiceService $createDraft): int
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

        $this->newLine();
        $this->warn('⚠ Esta operación CREARÁ un recurso draft real en Facturapi TEST.');
        $this->warn('  No será timbrado ni tendrá validez fiscal.');
        $this->newLine();

        if (! $this->confirm("¿Continuar y crear/sincronizar el borrador TEST para la Invoice [{$invoiceId}]?", false)) {
            $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

            return self::SUCCESS;
        }

        app(CurrentTenant::class)->set($invoice->company_id);

        try {
            $updated = $createDraft->createOrSync($invoice);
        } catch (Throwable $e) {
            $this->error('No se pudo crear/sincronizar el borrador: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $this->newLine();
        $this->info('Borrador TEST creado/sincronizado correctamente.');

        $this->table(
            ['Invoice local', 'Pac draft id', 'Status', 'is_ready_to_stamp', 'Última sincronización'],
            [[
                $updated->id,
                $updated->pac_draft_external_id,
                $updated->pac_draft_status,
                $updated->pac_draft_ready_to_stamp ? 'true' : 'false',
                optional($updated->pac_draft_last_sync_at)->toDateTimeString(),
            ]],
        );

        return self::SUCCESS;
    }
}
