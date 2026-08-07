<?php

namespace App\Console\Commands;

use App\Enums\CfdiCancellationMotive;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InvoiceWorkflow;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

class FacturapiTestCancelInvoice extends Command
{
    protected $signature = 'billing:facturapi-test-cancel
        {invoice : ID de la Invoice cuyo CFDI se solicitará cancelar}
        {--motive= : Motivo SAT: 01, 02, 03 o 04}
        {--substitution-uuid= : UUID del CFDI sustituto; obligatorio para motivo 01}';

    protected $description = 'Solicita la cancelación fiscal de un CFDI existente en Facturapi TEST.';

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

        $motiveValue = (string) $this->option('motive');
        $motive = CfdiCancellationMotive::tryFrom($motiveValue);

        if ($motive === null) {
            $this->error('La opción --motive es obligatoria y debe ser uno de: 01, 02, 03, 04.');

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
            $substitutionUuid = $this->normalizedOption('substitution-uuid');

            $this->newLine();
            $this->warn('Esta operación SOLICITARÁ la cancelación fiscal de un CFDI en Facturapi TEST.');
            $this->warn('El CFDI puede pasar a pending/verifying antes de ser aceptado.');
            $this->warn('Esta acción no elimina la Invoice ni sus XML/PDF.');
            $this->newLine();

            $this->table(
                ['Invoice', 'Folio', 'UUID', 'Motivo', 'UUID sustitución'],
                [[
                    $invoice->id,
                    $invoice->folio,
                    $this->maskUuid($invoice->cfdi_uuid),
                    $motive->value.' — '.$motive->label(),
                    $motive === CfdiCancellationMotive::ErrorsWithRelation
                        ? $this->maskUuid($substitutionUuid)
                        : 'no aplica',
                ]],
            );

            if (! $this->confirm("¿Confirmas que quieres SOLICITAR la cancelación fiscal de la Invoice [{$invoiceId}] en Facturapi TEST?", false)) {
                $this->info('Cancelado. No se realizó ninguna llamada HTTP.');

                return self::SUCCESS;
            }

            $updated = $workflow->cancelWithPac($invoice, $motive, $substitutionUuid);

            $this->newLine();
            $this->info('Solicitud de cancelación procesada por Facturapi TEST.');
            $this->table(
                ['Invoice local', 'Folio', 'Status local', 'Status PAC', 'Cancellation status', 'Reconciliation required', 'Last sync'],
                [[
                    $updated->id,
                    $updated->folio,
                    $updated->status->value,
                    $updated->pac_status ?? 'null',
                    $updated->cancellation_status ?? 'null',
                    $updated->pac_reconciliation_required ? 'true' : 'false',
                    optional($updated->last_pac_sync_at)->toDateTimeString() ?? 'null',
                ]],
            );

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('No se pudo solicitar la cancelación fiscal. Revisa pac_last_error y los logs sanitizados para el diagnóstico.');

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    private function normalizedOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function maskUuid(?string $uuid): string
    {
        return $uuid === null ? 'null' : mb_substr($uuid, 0, 8).'…'.mb_substr($uuid, -4);
    }
}
