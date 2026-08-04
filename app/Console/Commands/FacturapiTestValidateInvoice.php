<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InvoicePacReadinessService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Fase 6.2.3 — Herramienta manual (no automatizada) que prepara, sin
 * ejecutarla todavía, una futura verificación TEST real contra
 * Facturapi.
 *
 * LIMITACIÓN DOCUMENTADA (confirmada contra docs.facturapi.io/api/, ver
 * reporte de entrega Fase 6.2.3): Facturapi NO documenta ningún
 * parámetro `dry_run` para `POST /v2/invoices`. El mecanismo real más
 * cercano, `"status": "draft"`, SÍ crea y persiste un recurso del lado
 * de Facturapi (sin timbrar, sin UUID) — es una acción real con
 * consecuencias, no una validación pura sin efectos. Por eso este
 * comando, en esta fase, se limita EXCLUSIVAMENTE a la validación LOCAL
 * (InvoicePacReadinessService) — nunca realiza ninguna llamada HTTP.
 *
 * Aun así ya exige FACTURAPI_TEST_KEY y confirmación interactiva: es el
 * punto de partida para una futura extensión que sí contacte a
 * Facturapi (una vez que el equipo decida el mecanismo remoto correcto
 * — ver "riesgos" en el reporte de entrega). Nunca debe apuntar a una
 * llave live; se niega a correr en production.
 *
 * NO emite ningún CFDI. NO persiste pac_external_id/cfdi_uuid ni cambia
 * pac_issue_status — no escribe nada en la base de datos.
 */
class FacturapiTestValidateInvoice extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:facturapi-test-validate {invoice : ID de la Invoice a validar}';

    /**
     * @var string
     */
    protected $description = 'Valida localmente (sin HTTP) si una Invoice está lista fiscalmente para Facturapi TEST.';

    public function handle(InvoicePacReadinessService $readiness): int
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

        $this->line('Facturapi no documenta un mecanismo "dry_run" para Create Invoice — este comando solo ejecuta una validación LOCAL (sin llamadas HTTP, sin contactar a Facturapi).');

        if (! $this->confirm("¿Continuar con la validación TEST local de la Invoice [{$invoiceId}]?", false)) {
            $this->info('Cancelado. No se realizó ninguna acción.');

            return self::SUCCESS;
        }

        app(CurrentTenant::class)->set($invoice->company_id);

        try {
            $result = $readiness->evaluate($invoice);
        } finally {
            app(CurrentTenant::class)->clear();
        }

        $this->newLine();

        if ($result['ready']) {
            $this->info("Invoice [{$invoiceId}]: VALID — lista fiscalmente según la validación local.");

            return self::SUCCESS;
        }

        $errorCount = count($result['errors']);
        $this->error("Invoice [{$invoiceId}]: INVALID — {$errorCount} ".($errorCount === 1 ? 'error encontrado' : 'errores encontrados').':');

        $this->table(
            ['Código', 'Campo', 'Mensaje'],
            collect($result['errors'])->map(fn (array $error): array => [
                $error['code'],
                $error['field'],
                $error['message'],
            ])->all(),
        );

        return self::FAILURE;
    }
}
