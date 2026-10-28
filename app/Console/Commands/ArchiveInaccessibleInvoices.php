<?php

namespace App\Console\Commands;

use App\Enums\InvoicePacEventType;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Services\Billing\InvoicePacAuditService;
use App\Support\Billing\PacOrganizationAccess;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.27 — Cierre explícito de facturas emitidas en una organización
 * de Facturapi a la que su empresa ya no tiene acceso (p. ej. las #1-#3 de
 * la organización anterior de desarrollo).
 *
 * Sólo archiva facturas cuya organización registrada es distinta de la de
 * su empresa (PacOrganizationAccess::OTHER). Llena
 * `pac_organization_archived_at` y `pac_organization_archive_reason` y
 * registra el evento `organization_archived`. No toca el estado, el UUID ni
 * nada fiscal, y nunca borra. Todo o nada: si alguna factura pedida no se
 * puede archivar, no se archiva ninguna.
 *
 * Sin --apply sólo muestra lo que haría.
 */
class ArchiveInaccessibleInvoices extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:archive-inaccessible-invoices
        {--invoice=* : IDs de las facturas a archivar}
        {--reason= : Motivo del archivado (obligatorio)}
        {--apply : Archiva; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Archiva facturas de una organización de Facturapi inaccesible para su empresa (no toca datos fiscales).';

    public function handle(CurrentTenant $tenant, InvoicePacAuditService $audit): int
    {
        $ids = array_values(array_unique(array_map('intval', (array) $this->option('invoice'))));
        $reason = trim((string) $this->option('reason'));
        $apply = (bool) $this->option('apply');

        if ($ids === [] || $reason === '') {
            $this->error('Indica al menos una --invoice y el --reason del archivado.');

            return self::FAILURE;
        }

        if (mb_strlen($reason) > 255) {
            $this->error('El motivo no puede pasar de 255 caracteres.');

            return self::FAILURE;
        }

        $this->info('Archivado de facturas de una organización inaccesible'.($apply ? '' : ' — dry-run'));

        $previousTenant = $tenant->id();

        try {
            [$archivable, $refused, $rows] = $this->review($ids, $tenant);

            $this->table(['Factura', 'Empresa', 'Organización de la factura', 'Organización de la empresa', 'Resultado'], $rows);

            if ($refused > 0) {
                $this->error('Hay facturas que no se pueden archivar; no se archivó ninguna.');

                return self::FAILURE;
            }

            if (! $apply) {
                $this->warn('Dry-run: no se guardó nada. Repite con --apply para archivar.');

                return self::SUCCESS;
            }

            foreach ($archivable as $invoice) {
                $tenant->set((int) $invoice->company_id);

                DB::transaction(function () use ($invoice, $reason, $audit): void {
                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->whereNull('pac_organization_archived_at')
                        ->update([
                            'pac_organization_archived_at' => now(),
                            'pac_organization_archive_reason' => $reason,
                        ]);

                    $audit->append($invoice, InvoicePacEventType::OrganizationArchived, ['reason' => $reason]);
                });
            }
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }

        $this->info($archivable === [] ? 'No había facturas por archivar.' : sprintf('Archivadas: %s.', implode(', ', array_map(fn (Invoice $invoice): string => '#'.$invoice->id, $archivable))));

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return array{0: list<Invoice>, 1: int, 2: list<list<string>>}
     */
    private function review(array $ids, CurrentTenant $tenant): array
    {
        $archivable = [];
        $refused = 0;
        $rows = [];

        foreach ($ids as $id) {
            $invoice = Invoice::withoutGlobalScope(CompanyScope::class)->find($id);

            if ($invoice === null) {
                $refused++;
                $rows[] = ["#{$id}", '—', '—', '—', 'no existe'];

                continue;
            }

            $tenant->set((int) $invoice->company_id);
            $companyOrganization = PacOrganizationAccess::companyOrganizationId((int) $invoice->company_id);
            $status = PacOrganizationAccess::status($invoice, $companyOrganization);

            $result = match ($status) {
                PacOrganizationAccess::OTHER => 'se archiva',
                PacOrganizationAccess::ARCHIVED => 'ya está archivada',
                PacOrganizationAccess::CURRENT => 'no se archiva: es la organización de la empresa',
                PacOrganizationAccess::UNKNOWN => 'no se archiva: organización desconocida (corre billing:backfill-pac-organization o vincula la empresa)',
                default => 'no se archiva: nunca llegó al PAC',
            };

            if ($status === PacOrganizationAccess::OTHER) {
                $archivable[] = $invoice;
            } elseif ($status !== PacOrganizationAccess::ARCHIVED) {
                $refused++;
            }

            $rows[] = [
                "#{$invoice->id}",
                (string) $invoice->company_id,
                $invoice->pac_organization_id ?? '(vacía)',
                $companyOrganization ?? '(sin vincular)',
                $result,
            ];
        }

        return [$archivable, $refused, $rows];
    }
}
