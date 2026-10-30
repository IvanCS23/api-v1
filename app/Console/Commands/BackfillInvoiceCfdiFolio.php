<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\Billing\InvoiceFolio;
use App\Support\Billing\StoredCfdiFolio;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.28 — Llena `invoices.cfdi_series`/`cfdi_folio_number` de las
 * facturas timbradas antes de las columnas con la serie y el folio de su
 * respuesta ya guardada (pac_response, cifrada). Sin HTTP.
 *
 * Sin --apply sólo muestra lo que haría. Con --apply escribe únicamente
 * esas dos columnas, y sólo donde siguen vacías (no toca updated_at ni
 * nada fiscal), así que repetirlo no cambia nada. Las facturas sin folio
 * en la respuesta se reportan y se dejan como están.
 */
class BackfillInvoiceCfdiFolio extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:backfill-cfdi-folio
        {--company=* : Limita a estas empresas (por defecto, todas)}
        {--apply : Escribe las columnas; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Llena la serie y el folio del CFDI de cada factura timbrada desde su respuesta guardada (sin HTTP).';

    public function handle(CurrentTenant $tenant): int
    {
        $apply = (bool) $this->option('apply');
        $companyIds = array_map('intval', (array) $this->option('company'));
        $companies = Company::query()
            ->when($companyIds !== [], fn ($query) => $query->whereIn('id', $companyIds))
            ->orderBy('id')
            ->get();

        $this->info('Serie y folio del CFDI por factura'.($apply ? '' : ' — dry-run'));

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

        $this->line(sprintf('%s: %d. Sin folio en la respuesta guardada: %d.', $apply ? 'Llenadas' : 'Por llenar', $totals['filled'], $totals['skipped']));

        if (! $apply) {
            $this->warn('Dry-run: no se guardó nada. Repite con --apply para escribir.');
        }

        return self::SUCCESS;
    }

    /** @return array{filled: int, skipped: int} */
    private function backfillCompany(Company $company, bool $apply): array
    {
        $rows = [];
        $filled = 0;
        $skipped = 0;

        Invoice::withTrashed()
            ->whereNull('cfdi_folio_number')
            ->whereNotNull('pac_external_id')
            ->orderBy('id')
            ->each(function (Invoice $invoice) use ($apply, &$rows, &$filled, &$skipped): void {
                $folio = StoredCfdiFolio::of($invoice);

                if ($folio === null) {
                    $skipped++;
                    $rows[] = [$invoice->id, $invoice->folio, '(sin folio guardado)', '—', 'se deja vacía'];

                    return;
                }

                if ($apply) {
                    DB::table('invoices')
                        ->where('id', $invoice->id)
                        ->whereNull('cfdi_folio_number')
                        ->update(['cfdi_series' => $folio['series'], 'cfdi_folio_number' => $folio['folio_number']]);
                }

                $label = (string) InvoiceFolio::label($folio['series'], $folio['folio_number']);
                $expected = InvoiceFolio::parse($invoice->folio)?->cfdiLabel();

                $filled++;
                $rows[] = [
                    $invoice->id,
                    $invoice->folio,
                    $label,
                    $expected === $label ? 'sí' : 'no (anterior a la 6.28)',
                    $apply ? 'llenada' : 'se llenaría',
                ];
            });

        if ($rows === []) {
            return ['filled' => 0, 'skipped' => 0];
        }

        $this->line(sprintf('Empresa %d (%s):', $company->id, $company->trade_name));
        $this->table(['Factura', 'Folio ERP', 'Folio CFDI guardado', '¿Coincide con el ERP?', 'Acción'], $rows);

        return ['filled' => $filled, 'skipped' => $skipped];
    }
}
