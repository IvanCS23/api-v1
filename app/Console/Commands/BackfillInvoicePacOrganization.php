<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Billing\PacOrganizationAccess;
use App\Support\Billing\StoredPacOrganization;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.27 — Llena `invoices.pac_organization_id` de las facturas
 * anteriores a la columna con el `organization` de su respuesta ya
 * guardada (pac_response / pac_draft_response, cifradas). Sin HTTP.
 *
 * Sin --apply sólo muestra lo que haría. Con --apply escribe únicamente
 * esa columna, y sólo donde sigue vacía (no toca updated_at ni nada
 * fiscal). Las facturas sin organización guardada o con respuestas en
 * conflicto se reportan y se dejan como están.
 */
class BackfillInvoicePacOrganization extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:backfill-pac-organization
        {--company=* : Limita a estas empresas (por defecto, todas)}
        {--apply : Escribe la columna; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Llena la organización de Facturapi de cada factura desde su respuesta guardada (sin HTTP).';

    public function handle(CurrentTenant $tenant): int
    {
        $apply = (bool) $this->option('apply');
        $companyIds = array_map('intval', (array) $this->option('company'));
        $companies = Company::query()
            ->when($companyIds !== [], fn ($query) => $query->whereIn('id', $companyIds))
            ->orderBy('id')
            ->get();

        $this->info('Organización de Facturapi por factura'.($apply ? '' : ' — dry-run'));

        $previousTenant = $tenant->id();
        $totals = ['filled' => 0, 'skipped' => 0];

        try {
            foreach ($companies as $company) {
                $tenant->set($company->id);
                $result = $this->backfillCompany($company, $apply);
                $totals['filled'] += $result['filled'];
                $totals['skipped'] += $result['skipped'];
            }
        } finally {
            $previousTenant === null ? $tenant->clear() : $tenant->set($previousTenant);
        }

        $this->line(sprintf('%s: %d. Sin organización guardada o en conflicto: %d.', $apply ? 'Llenadas' : 'Por llenar', $totals['filled'], $totals['skipped']));

        if (! $apply) {
            $this->warn('Dry-run: no se guardó nada. Repite con --apply para escribir.');
        }

        return self::SUCCESS;
    }

    /** @return array{filled: int, skipped: int} */
    private function backfillCompany(Company $company, bool $apply): array
    {
        $companyOrganization = PacOrganizationAccess::companyOrganizationId($company->id);
        $rows = [];
        $filled = 0;
        $skipped = 0;

        Invoice::withTrashed()
            ->whereNull('pac_organization_id')
            ->where(fn ($query) => $query->whereNotNull('pac_external_id')->orWhereNotNull('pac_draft_external_id'))
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($companyOrganization, $apply, &$rows, &$filled, &$skipped): void {
                $organization = StoredPacOrganization::of($invoice);

                if ($organization === null) {
                    $skipped++;
                    $rows[] = [$invoice->id, StoredPacOrganization::conflicts($invoice) ? '(respuestas en conflicto)' : '(sin organización guardada)', '—', 'se deja vacía'];

                    return;
                }

                if ($apply) {
                    DB::table('invoices')->where('id', $invoice->id)->whereNull('pac_organization_id')->update(['pac_organization_id' => $organization]);
                }

                $filled++;
                $rows[] = [
                    $invoice->id,
                    $organization,
                    $companyOrganization === null ? '(empresa sin organización)' : ($organization === $companyOrganization ? 'sí' : 'NO'),
                    $apply ? 'llenada' : 'se llenaría',
                ];
            });

        if ($rows === []) {
            return ['filled' => 0, 'skipped' => 0];
        }

        $this->line(sprintf('Empresa %d (%s), organización %s:', $company->id, $company->trade_name, $companyOrganization ?? '(sin vincular)'));
        $this->table(['Factura', 'Organización guardada', '¿Es la de la empresa?', 'Acción'], $rows);

        return ['filled' => $filled, 'skipped' => $skipped];
    }
}
