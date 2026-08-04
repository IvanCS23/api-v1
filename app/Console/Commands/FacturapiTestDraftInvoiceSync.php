<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\SyncPacDraftInvoiceService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Fase 6.2.4 — Consulta (nunca crea) el estado real de un borrador ya
 * conocido en Facturapi TEST. Realiza una llamada HTTP real (cuando un
 * humano lo ejecuta manualmente, nunca durante esta tarea) — de solo
 * lectura del lado del PAC, pero de todos modos exige confirmación por
 * consistencia con el resto de comandos TEST de esta fase.
 *
 * Si la Invoice no tiene `pac_draft_external_id` todavía, este comando
 * FALLA localmente — nunca busca ni crea un borrador en su lugar (eso
 * es responsabilidad exclusiva de `billing:facturapi-test-draft`).
 */
class FacturapiTestDraftInvoiceSync extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-test-draft-sync {invoice : ID de la Invoice cuyo borrador ya conocido se sincronizará}';

    /**
     * @var string
     */
    protected $description = 'Sincroniza (sin crear) el estado real de un borrador ya conocido en Facturapi TEST.';

    public function handle(SyncPacDraftInvoiceService $sync): int
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
            $this->error("La Invoice [{$invoiceId}] no tiene un borrador remoto registrado (pac_draft_external_id vacío). Este comando nunca crea uno — usa billing:facturapi-test-draft primero.");

            return self::FAILURE;
        }

        if (! $this->confirm("¿Continuar y consultar en Facturapi TEST el borrador de la Invoice [{$invoiceId}]?", false)) {
            $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

            return self::SUCCESS;
        }

        app(CurrentTenant::class)->set($invoice->company_id);

        try {
            $updated = $sync->sync($invoice);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se pudo sincronizar el borrador: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $this->newLine();
        $this->info('Borrador TEST sincronizado correctamente.');

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
