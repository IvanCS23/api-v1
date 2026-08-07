<?php

namespace App\Console\Commands;

use App\Exceptions\Billing\PacException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\AuditCancellationIdentityService;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Throwable;

class FacturapiTestAuditCancellationIdentity extends Command
{
    protected $signature = 'billing:facturapi-test-cancellation-identity-audit
        {invoice : ID de la Invoice cancelada cuya identidad se auditara}';

    protected $description = 'Compara identidades DB/remota/acuse/CFDI original sin modificar datos.';

    public function handle(AuditCancellationIdentityService $service): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando esta prohibido en production. Solo se ejecuta en entornos local/testing.');

            return self::FAILURE;
        }

        if (blank(config('services.facturapi.test_key'))) {
            $this->error('FACTURAPI_TEST_KEY no esta configurada. Usa exclusivamente una llave TEST.');

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

            $this->warn('AUDITORIA DE SOLO LECTURA: ejecutara retrieveInvoice y descargara solo el XML del acuse.');
            $this->warn('No cancela, no guarda artifacts, no modifica DB y no solicita PDF.');

            if (! $this->confirm("Confirmas la auditoria de identidad de la Invoice [{$invoiceId}]?", false)) {
                $this->info('Cancelado. No se realizo ninguna llamada HTTP.');

                return self::SUCCESS;
            }

            $audit = $service->audit($invoice);

            $this->line('A_local_uuid = '.$audit['local']['uuid']);
            $this->line('B_remote_uuid = '.($audit['remote']['uuid'] ?? 'null'));
            $this->line('C_receipt_uuids = '.json_encode($audit['receipt']['uuids']));
            $this->line('D_cfdi_xml_uuid = '.($audit['cfdi_xml']['uuid'] ?? 'null'));

            $this->table(
                ['Fuente', 'ID/UUID', 'Estado', 'Cancellation', 'Livemode', 'Stamp date'],
                [
                    ['A local DB', $audit['local']['uuid'], '-', '-', '-', '-'],
                    [
                        'B retrieve remoto',
                        $audit['remote']['uuid'] ?? 'null',
                        $audit['remote']['status'] ?? 'null',
                        $audit['remote']['cancellation_status'] ?? 'null',
                        $this->bool($audit['remote']['livemode']),
                        $audit['remote']['stamp_date'] ?? 'null',
                    ],
                    ['C Acuse/Folios/UUID', implode(', ', $audit['receipt']['uuids']), '-', '-', '-', '-'],
                    [
                        'D TimbreFiscalDigital/@UUID',
                        $audit['cfdi_xml']['uuid'] ?? 'null',
                        $audit['cfdi_xml']['state'],
                        '-',
                        '-',
                        '-',
                    ],
                ],
            );

            $this->line('remote_id = '.$audit['remote']['id']);
            $this->line('remote_id_matches_local = '.$this->bool($audit['remote']['id_matches_local']));
            $this->line('cfdi_xml_hash_matches_metadata = '.$this->bool($audit['cfdi_xml']['hash_matches_metadata']));
            $this->line('receipt_xpath = '.$audit['receipt']['xpath']);

            $this->table(
                ['Comparacion', 'Resultado'],
                array_map(
                    fn (string $name, ?bool $matches): array => [$name, $this->bool($matches)],
                    array_keys($audit['comparisons']),
                    array_values($audit['comparisons']),
                ),
            );

            $this->table(
                ['Snapshot local', 'Presente', 'Status', 'UUID state', 'UUID', 'Coincide local'],
                [
                    $this->historyRow('pac_response actual', $audit['history']['pac_response']),
                    $this->historyRow('pac_draft_response actual', $audit['history']['pac_draft_response']),
                ],
            );
            $this->line('pac_response_current_uuid = '.($audit['history']['pac_response']['uuid'] ?? 'null'));
            $this->line('pac_draft_response_current_uuid = '.($audit['history']['pac_draft_response']['uuid'] ?? 'null'));
            $this->line('historical_note = '.$audit['history']['note']);
            $this->info('scenario = '.$audit['scenario']);
            $this->info('Auditoria terminada sin persistencia.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No se pudo completar la auditoria. No se modificaron DB ni artifacts.');

            if (($pacCode = $this->safePacCode($e)) !== null) {
                $this->line('Codigo PAC seguro: '.$pacCode);
            }

            return self::FAILURE;
        } finally {
            app(CurrentTenant::class)->clear();
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function historyRow(string $name, array $snapshot): array
    {
        return [
            $name,
            $this->bool($snapshot['present']),
            $snapshot['status'] ?? 'null',
            $snapshot['uuid_state'],
            $snapshot['uuid'] ?? 'null',
            $this->bool($snapshot['uuid_matches_local']),
        ];
    }

    private function bool(?bool $value): string
    {
        return $value === null ? 'unknown' : ($value ? 'yes' : 'no');
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
