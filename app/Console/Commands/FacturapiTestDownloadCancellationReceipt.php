<?php

namespace App\Console\Commands;

use App\Exceptions\Billing\CancellationReceiptUnavailableException;
use App\Exceptions\Billing\PacException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\DownloadCancellationReceiptService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Entrada manual y controlada para descargar un acuse exclusivamente
 * desde Facturapi TEST. No cancela, timbra ni modifica artifacts CFDI.
 */
class FacturapiTestDownloadCancellationReceipt extends Command
{
    protected $signature = 'billing:facturapi-test-cancellation-receipt
        {invoice : ID de la Invoice cancelada y aceptada cuyo acuse se descargara}';

    protected $description = 'Descarga y almacena privadamente el acuse XML/PDF de una cancelacion aceptada en Facturapi TEST.';

    public function handle(DownloadCancellationReceiptService $service): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando esta prohibido en production. Solo se ejecuta en entornos local/testing.');

            return self::FAILURE;
        }

        if (blank(config('services.facturapi.test_key'))) {
            $this->error('FACTURAPI_TEST_KEY no esta configurada. Configurala con una llave de TEST antes de continuar.');

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

            if ($invoice->pac_status !== 'canceled') {
                $this->error("La Invoice [{$invoiceId}] no tiene pac_status=canceled; no se descargara ningun acuse.");

                return self::FAILURE;
            }

            if ($invoice->cancellation_status !== 'accepted') {
                $detail = in_array($invoice->cancellation_status, ['pending', 'verifying'], true)
                    ? 'La cancelacion sigue en curso y el acuse aun no esta disponible.'
                    : 'cancellation_status debe ser accepted.';
                $this->error("La Invoice [{$invoiceId}] no es elegible. {$detail}");

                return self::FAILURE;
            }

            if ($invoice->pac_external_id === null || $invoice->cfdi_uuid === null) {
                $this->error("La Invoice [{$invoiceId}] requiere pac_external_id y cfdi_uuid.");

                return self::FAILURE;
            }

            if (! Schema::hasColumns('invoices', [
                'cancellation_receipt_status',
                'cancellation_receipt_last_error',
                'cancellation_receipt_xml_path',
                'cancellation_receipt_pdf_path',
            ])) {
                $this->error('La migración de FASE 6.6 no está aplicada.');
                $this->line('Ejecuta php artisan migrate antes de descargar el acuse.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->warn('Esta operación DESCARGARÁ el acuse XML/PDF de cancelación desde Facturapi TEST.');
            $this->warn('No cancelará nuevamente el CFDI ni modificará sus artifacts originales.');
            $this->newLine();
            $this->table(
                ['Invoice', 'Folio', 'UUID', 'PAC status', 'Cancellation status'],
                [[
                    $invoice->id,
                    $invoice->folio,
                    $this->maskUuid($invoice->cfdi_uuid),
                    $invoice->pac_status,
                    $invoice->cancellation_status,
                ]],
            );

            if (! $this->confirm("Confirmas que quieres descargar el acuse de cancelacion de la Invoice [{$invoiceId}] desde Facturapi TEST?", false)) {
                $this->info('Cancelado. No se realizo ninguna llamada HTTP.');

                return self::SUCCESS;
            }

            $result = $service->download($invoice);
            $fresh = $invoice->fresh();

            $this->newLine();
            $this->info('Acuse de cancelacion obtenido correctamente.');
            $this->table(
                ['Invoice', 'Folio', 'UUID', 'XML guardado', 'PDF guardado', 'XML bytes', 'PDF bytes', 'XML sha256', 'PDF sha256', 'Fecha'],
                [[
                    $fresh->id,
                    $fresh->folio,
                    $this->maskUuid($fresh->cfdi_uuid),
                    $fresh->cancellation_receipt_xml_path !== null ? 'si' : 'no',
                    $fresh->cancellation_receipt_pdf_path !== null ? 'si' : 'no',
                    $result->xmlSize,
                    $result->pdfSize,
                    mb_substr($result->xmlHash, 0, 12).'...',
                    mb_substr($result->pdfHash, 0, 12).'...',
                    $result->downloadedAt->toDateTimeString(),
                ]],
            );

            return self::SUCCESS;
        } catch (CancellationReceiptUnavailableException $e) {
            $this->error('ACUSE NO DISPONIBLE AÚN. Facturapi todavía no expone el acuse; la Invoice quedó marcada para reconciliación.');
            $this->line('Código PAC seguro: '.$e->pacCode);

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('ERROR DE DESCARGA / VALIDACIÓN. Revisa cancellation_receipt_last_error y el evento de log sanitizado.');

            if (($pacCode = $this->safePacCode($e)) !== null) {
                $this->line('Código PAC seguro: '.$pacCode);
            }

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    private function maskUuid(?string $uuid): string
    {
        return $uuid === null ? 'null' : mb_substr($uuid, 0, 8).'...'.mb_substr($uuid, -4);
    }

    private function safePacCode(Throwable $e): ?string
    {
        if (! $e instanceof PacException
            || $e->pacCode === null
            || preg_match('/^[a-z0-9_.-]{1,100}$/i', $e->pacCode) !== 1) {
            return null;
        }

        return $e->pacCode;
    }
}
