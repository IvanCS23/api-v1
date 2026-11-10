<?php

namespace App\Console\Commands;

use App\Services\Taxes\LineTaxBackfill;
use App\Services\Taxes\LineTaxConsistency;
use Illuminate\Console\Command;

/**
 * Fase 6.34 (2): pasa el impuesto único de antes a las tablas de impuestos
 * por producto y por línea (ver LineTaxBackfill). Sin --apply sólo muestra
 * lo que haría. Con --apply escribe en una transacción y al final corre la
 * verificación (billing:tax-lines-check). Sin HTTP.
 */
class BackfillLineTaxes extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:backfill-line-taxes {--apply : Escribe; sin esta opción sólo muestra lo que haría}';

    /**
     * @var string
     */
    protected $description = 'Pasa el impuesto de cada producto y línea (tax_rate_id y snapshot) a las tablas de impuestos por línea.';

    public function handle(LineTaxBackfill $backfill, LineTaxConsistency $consistency): int
    {
        $apply = (bool) $this->option('apply');
        $plan = $backfill->plan();

        $this->info('Impuestos por línea'.($apply ? '' : ' — dry-run'));
        $this->line('  product_taxes: '.count($plan['product_taxes']));
        foreach ($plan['line_taxes'] as $table => $rows) {
            $this->line("  {$table}: ".count($rows).' impuestos, '.count($plan['line_totals'][$table]).' líneas con totales nuevos');
        }
        foreach ($plan['document_totals'] as $table => $ids) {
            $this->line("  {$table}: ".count($ids).' documentos con totales nuevos');
        }

        if ($plan['conflicts'] !== []) {
            $this->error('Revisar a mano (no se escribió nada):');
            foreach ($plan['conflicts'] as $conflict) {
                $this->line("  - {$conflict}");
            }

            return self::FAILURE;
        }

        if (! $apply) {
            $this->comment('Sin --apply no se escribió nada.');

            return self::SUCCESS;
        }

        $backfill->apply($plan);
        $issues = $consistency->check();
        foreach ($issues as $issue) {
            $this->error("  {$issue}");
        }
        $this->info($issues === [] ? 'Aplicado; la verificación cuadra.' : 'Aplicado, pero la verificación encontró '.count($issues).' diferencias.');

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
