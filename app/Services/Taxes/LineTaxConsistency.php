<?php

namespace App\Services\Taxes;

use App\Enums\TaxType;
use App\Models\Invoice;
use App\Models\Quote;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.34: verifica, sin escribir nada, que los impuestos por línea y los
 * totales cuadren con el cálculo estilo SAT. Recorre todas las empresas
 * (sin global scopes).
 *
 * Por línea (cotización, venta y factura), con los importes exactos:
 * - trasladados = `taxes_transferred` = `tax_total` = redondeo de la suma
 *   exacta de sus traslados; retenidos = `taxes_withheld`, igual;
 * - total = redondeo de max(importe − descuento, 0) + trasladados −
 *   retenidos (importe = `subtotal_exact`, o `subtotal` en líneas
 *   anteriores).
 *
 * Por documento: sus seis totales = DocumentTaxTotals de sus líneas (la
 * suma de las líneas redondeadas puede diferir por centavos: es correcto).
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
            $exact = DB::table($taxTable)
                ->where('factor', '!=', 'exento')
                ->get([$foreignKey, 'kind', 'amount', 'amount_rounded'])
                ->groupBy($foreignKey);

            foreach (DB::table($table)->orderBy('id')->get(['id', 'subtotal', 'subtotal_exact', 'discount', 'tax_total', 'taxes_transferred', 'taxes_withheld', 'total']) as $line) {
                $transferred = '0';
                $withheld = '0';
                foreach ($exact[$line->id] ?? [] as $tax) {
                    $amount = DecimalMath::of($tax->amount ?? $tax->amount_rounded);
                    $tax->kind === TaxType::Retencion->value
                        ? $withheld = DecimalMath::add($withheld, $amount)
                        : $transferred = DecimalMath::add($transferred, $amount);
                }

                $importe = DecimalMath::of($line->subtotal_exact ?? $line->subtotal);
                $base = DecimalMath::max(DecimalMath::sub($importe, DecimalMath::of($line->discount)), '0');
                $expected = [
                    'taxes_transferred' => DecimalMath::round($transferred, 2),
                    'tax_total' => DecimalMath::round($transferred, 2),
                    'taxes_withheld' => DecimalMath::round($withheld, 2),
                    'total' => DecimalMath::round(DecimalMath::sub(DecimalMath::add($base, $transferred), $withheld), 2),
                ];

                foreach ($expected as $column => $value) {
                    if (DecimalMath::round(DecimalMath::of($line->{$column}), 2) !== $value) {
                        $issues[] = "{$table} #{$line->id}: {$column} {$line->{$column}}, se esperaba {$value}";
                    }
                }
            }
        }

        $totals = new DocumentTaxTotals;
        foreach (['quotes' => Quote::class, 'sales' => Sale::class, 'invoices' => Invoice::class] as $table => $model) {
            $model::withoutGlobalScope(CompanyScope::class)
                ->with(['items' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class)])
                ->orderBy('id')->chunk(200, function ($documents) use ($totals, $table, &$issues): void {
                    foreach ($documents as $document) {
                        $computed = $totals->compute($document->items);
                        if (! DocumentTaxTotals::matches($document, $computed, array_keys($computed))) {
                            $issues[] = "{$table} #{$document->id}: totales guardados (total {$document->total}) ≠ calculados (total {$computed['total']})";
                        }
                    }
                });
        }

        return $issues;
    }
}
