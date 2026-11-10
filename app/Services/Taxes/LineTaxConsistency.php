<?php

namespace App\Services\Taxes;

use App\Enums\TaxType;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.34 (2): verifica, sin escribir nada, que los impuestos por línea y
 * los totales cuadren. Recorre todas las empresas (sin global scopes).
 *
 * Por línea (cotización, venta y factura):
 * - suma de `amount_rounded` de los traslados = `taxes_transferred` = `tax_total`;
 * - suma de `amount_rounded` de las retenciones = `taxes_withheld`;
 * - `total` = max(subtotal − descuento, 0) + trasladados − retenidos.
 *
 * Por documento: `taxes_transferred` = `tax_total`.
 */
class LineTaxConsistency
{
    /**
     * @return list<string> diferencias encontradas (vacía si todo cuadra)
     */
    public function check(): array
    {
        $issues = [];

        foreach (LineTaxBackfill::LINE_TABLES as $table => [$taxTable, $foreignKey]) {
            $sums = DB::table($taxTable)
                ->select($foreignKey, 'kind', DB::raw('SUM(amount_rounded) as total'))
                ->groupBy($foreignKey, 'kind')
                ->get()
                ->groupBy($foreignKey);

            foreach (DB::table($table)->orderBy('id')->get(['id', 'subtotal', 'discount', 'tax_total', 'taxes_transferred', 'taxes_withheld', 'total']) as $line) {
                $byKind = ($sums[$line->id] ?? collect())->keyBy('kind');
                $transferred = $this->cents($byKind[TaxType::Traslado->value]->total ?? 0);
                $withheld = $this->cents($byKind[TaxType::Retencion->value]->total ?? 0);
                $base = max($this->cents($line->subtotal) - $this->cents($line->discount), 0);

                if ($transferred !== $this->cents($line->taxes_transferred) || $transferred !== $this->cents($line->tax_total)) {
                    $issues[] = "{$table} #{$line->id}: traslados por impuesto {$this->money($transferred)}, taxes_transferred {$line->taxes_transferred}, tax_total {$line->tax_total}";
                }
                if ($withheld !== $this->cents($line->taxes_withheld)) {
                    $issues[] = "{$table} #{$line->id}: retenciones por impuesto {$this->money($withheld)}, taxes_withheld {$line->taxes_withheld}";
                }
                if ($base + $this->cents($line->taxes_transferred) - $this->cents($line->taxes_withheld) !== $this->cents($line->total)) {
                    $issues[] = "{$table} #{$line->id}: total {$line->total} ≠ subtotal − descuento + trasladados − retenidos";
                }
            }
        }

        foreach (LineTaxBackfill::DOCUMENT_TABLES as $table) {
            foreach (DB::table($table)->whereColumn('taxes_transferred', '!=', 'tax_total')->orderBy('id')->get(['id', 'tax_total', 'taxes_transferred']) as $document) {
                $issues[] = "{$table} #{$document->id}: taxes_transferred {$document->taxes_transferred} ≠ tax_total {$document->tax_total}";
            }
        }

        return $issues;
    }

    /** Centavos enteros: compara importes de 2 decimales sin errores de float. */
    private function cents(mixed $value): int
    {
        return (int) bcmul(number_format((float) $value, 2, '.', ''), '100', 0);
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
