<?php

namespace App\Console\Commands;

use App\Services\Taxes\LineTaxConsistency;
use Illuminate\Console\Command;

/**
 * Fase 6.34 (2): verifica, sin escribir nada, que los impuestos por línea
 * cuadren con los totales de líneas y documentos (ver LineTaxConsistency).
 */
class TaxLinesCheck extends Command
{
    /**
     * @var string
     */
    protected $signature = 'billing:tax-lines-check';

    /**
     * @var string
     */
    protected $description = 'Verifica que los impuestos por línea cuadren con los totales (sólo lectura).';

    public function handle(LineTaxConsistency $consistency): int
    {
        $issues = $consistency->check();
        foreach ($issues as $issue) {
            $this->error($issue);
        }
        $this->info($issues === [] ? 'Los impuestos por línea cuadran.' : count($issues).' diferencias.');

        return $issues === [] ? self::SUCCESS : self::FAILURE;
    }
}
