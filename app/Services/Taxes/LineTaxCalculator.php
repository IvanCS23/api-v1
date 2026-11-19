<?php

namespace App\Services\Taxes;

use App\Enums\TaxFactorType;

/**
 * Fase 6.34 (3, ajustado al cálculo estilo SAT): una línea con varios
 * impuestos, con aritmética decimal exacta (bcmath, nunca float).
 *
 * - Importe = cantidad × valor unitario, con 6 decimales (`subtotal_exact`),
 *   sin redondear a 2 antes de calcular los impuestos (como Facturapi: caso
 *   G del paso 7). `subtotal` es ese importe redondeado a 2, para mostrar.
 * - Base = max(importe − descuento, 0), con 6 decimales.
 * - IEPS trasladado (sum_before_taxes, D1) primero; su importe exacto se
 *   suma a la base de los demás impuestos de la línea.
 * - Tasa: base × tasa. Cuota: base = cantidad, cantidad × cuota. Exento:
 *   sin importe. `amount` con 6 decimales; `amount_rounded` a 2 (sólo para
 *   mostrar cada impuesto).
 * - Columnas de la línea (para mostrar): trasladados y retenidos son el
 *   redondeo de la suma exacta; total = redondeo de base + trasladados −
 *   retenidos exactos. `tax_total` = trasladados.
 * - Los totales del documento NO suman estas columnas redondeadas: ver
 *   DocumentTaxTotals (redondeo de la suma de los exactos, como el CFDI).
 */
class LineTaxCalculator
{
    /**
     * @param  list<TaxSpec>  $specs
     * @return array{subtotal: string, subtotal_exact: string, discount: string, base: string, tax_total: string, taxes_transferred: string, taxes_withheld: string, total: string, taxes: list<array{tax: string, kind: string, factor: string, rate: ?string, ieps_mode: ?string, position: int, base: string, amount: ?string, amount_rounded: string}>}
     */
    public function calculate(mixed $quantity, mixed $unitPrice, mixed $discount, array $specs): array
    {
        $quantity = DecimalMath::of($quantity);
        $importe = DecimalMath::round(DecimalMath::mul($quantity, DecimalMath::of($unitPrice)), 6);
        $discount = DecimalMath::round(DecimalMath::of($discount), 2);
        $base = DecimalMath::round(DecimalMath::max(DecimalMath::sub($importe, $discount), '0'), 6);

        // IEPS trasladado primero: su importe forma parte de la base del resto.
        $ordered = [];
        foreach ($specs as $position => $spec) {
            $ordered[] = [$spec->isTransferredIeps() ? 0 : 1, $position, $spec];
        }
        usort($ordered, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $iepsAmount = '0';
        $rows = [];
        $transferred = '0';
        $withheld = '0';

        foreach ($ordered as [, $position, $spec]) {
            $taxBase = $spec->isTransferredIeps() ? $base : DecimalMath::add($base, $iepsAmount);
            $amount = null;

            if ($spec->factor === TaxFactorType::Cuota) {
                $taxBase = $quantity;
                $amount = DecimalMath::mul($quantity, (string) $spec->rate);
            } elseif ($spec->factor === TaxFactorType::Tasa) {
                $amount = DecimalMath::mul($taxBase, (string) $spec->rate);
            }

            $exact = $amount === null ? null : DecimalMath::round($amount, 6);

            if ($exact !== null) {
                if ($spec->isTransferredIeps()) {
                    $iepsAmount = DecimalMath::add($iepsAmount, $exact);
                }
                $spec->isWithholding()
                    ? $withheld = DecimalMath::add($withheld, $exact)
                    : $transferred = DecimalMath::add($transferred, $exact);
            }

            $rows[$position] = [
                ...$spec->toArray(),
                'position' => $position,
                'base' => DecimalMath::round($taxBase, 6),
                'amount' => $exact,
                'amount_rounded' => $exact === null ? '0.00' : DecimalMath::round($exact, 2),
            ];
        }
        ksort($rows);

        $transferredRounded = DecimalMath::round($transferred, 2);

        return [
            'subtotal' => DecimalMath::round($importe, 2),
            'subtotal_exact' => $importe,
            'discount' => $discount,
            'base' => DecimalMath::round($base, 2),
            'tax_total' => $transferredRounded,
            'taxes_transferred' => $transferredRounded,
            'taxes_withheld' => DecimalMath::round($withheld, 2),
            'total' => DecimalMath::round(DecimalMath::sub(DecimalMath::add($base, $transferred), $withheld), 2),
            'taxes' => array_values($rows),
        ];
    }
}
