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
 * Diagnostico manual y efimero de la estructura del XML de un acuse.
 * No persiste bytes ni metadata y nunca solicita la representacion PDF.
 */
class FacturapiTestInspectCancellationReceiptXml extends Command
{
    protected $signature = 'billing:facturapi-test-cancellation-receipt-inspect
        {invoice : ID de la Invoice cancelada cuyo XML de acuse se inspeccionara}';

    protected $description = 'Muestra la estructura sanitizada del XML de acuse descargado desde Facturapi TEST, sin persistirlo.';

    public function handle(InspectCancellationReceiptXmlService $service): int
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

            if ($invoice->pac_status !== 'canceled'
                || $invoice->cancellation_status !== 'accepted'
                || $invoice->pac_external_id === null
                || $invoice->cfdi_uuid === null) {
                $this->error("La Invoice [{$invoiceId}] requiere PAC cancelado/aceptado, pac_external_id y cfdi_uuid.");

                return self::FAILURE;
            }

            $this->newLine();
            $this->warn('Esta operacion descargara SOLO el XML del acuse desde Facturapi TEST.');
            $this->warn('No solicitara PDF, no guardara artifacts y no modificara metadata de la Invoice.');
            $this->table(
                ['Invoice', 'Folio', 'UUID local'],
                [[$invoice->id, $invoice->folio, $this->maskUuid($invoice->cfdi_uuid)]],
            );

            if (! $this->confirm("Confirmas la inspeccion de solo lectura de la Invoice [{$invoiceId}]?", false)) {
                $this->info('Cancelado. No se realizo ninguna llamada HTTP.');

                return self::SUCCESS;
            }

            $inspection = $service->inspect($invoice);

            $this->newLine();
            $this->info('Estructura XML sanitizada:');
            $this->line('root = '.$inspection['root']);
            $this->line('root_namespace = '.$inspection['root_namespace']);
            $this->line('namespaces = '.json_encode($inspection['namespaces'], JSON_UNESCAPED_SLASHES));
            $this->line('elements = '.json_encode($inspection['elements'], JSON_UNESCAPED_SLASHES));

            $this->table(
                ['kind', 'location', 'name'],
                array_map(
                    fn (array $field): array => [$field['kind'], $field['location'], $field['name']],
                    $inspection['uuid_fields'],
                ),
            );
            $this->line('uuid_candidates = '.json_encode(
                array_column($inspection['uuid_candidates'], 'value'),
                JSON_UNESCAPED_SLASHES,
            ));
            $this->table(
                ['#', 'kind', 'location', 'name', 'masked_uuid', 'matches_local'],
                array_map(
                    fn (array $candidate, int $index): array => [
                        $index + 1,
                        $candidate['kind'],
                        $candidate['location'],
                        $candidate['name'],
                        $candidate['value'],
                        $candidate['matches_invoice'] ? 'yes' : 'no',
                    ],
                    $inspection['uuid_candidates'],
                    array_keys($inspection['uuid_candidates']),
                ),
            );

            $this->info('Diagnostico terminado: el XML fue descartado sin persistirse.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo inspeccionar el XML. No se persistieron bytes ni metadata.');

            if (($pacCode = $this->safePacCode($e)) !== null) {
                $this->line('Codigo PAC seguro: '.$pacCode);
            }

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    private function maskUuid(string $uuid): string
    {
        return mb_substr($uuid, 0, 8).'...'.mb_substr($uuid, -4);
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
