<?php

namespace App\Services\Taxes;

use App\Enums\TaxFactorType;

/**
 * Fase 6.34 (3): cálculo exacto de una línea con varios impuestos.
 *
 * - Aritmética decimal con bcmath (texto), nunca float.
 * - Importe (subtotal) = cantidad × valor unitario, redondeado a 2 decimales
 *   como hasta ahora; base = max(importe − descuento, 0).
 * - IEPS trasladado (sum_before_taxes, D1) se calcula primero y su importe
 *   exacto se suma a la base de los demás impuestos de la línea.
 * - Tasa: importe = base × tasa. Cuota: base = cantidad, importe =
 *   cantidad × cuota. Exento: sin importe.
 * - `amount`: importe exacto redondeado a 6 decimales (máximo del Anexo 20
 *   por concepto). `amount_rounded`: a 2 decimales, el que suman los
 *   totales. Redondeo: la mitad se aleja del cero.
 * - Traslados y retenciones se suman por separado:
 *   total = base + trasladados − retenidos. `tax_total` = trasladados.
 *   (Antes, una retención se sumaba como si fuera un traslado.)
 *
 * Por confirmar con la guía de llenado general / Anexo 20: si el importe por
 * concepto se redondea a 2 o a 6 decimales antes de sumar, y los límites de
 * tolerancia. Se compara contra Facturapi TEST en el paso 7.
 */
class LineTaxCalculator
{
    /**
     * @param  list<TaxSpec>  $specs
     * @return array{subtotal: string, discount: string, base: string, tax_total: string, taxes_transferred: string, taxes_withheld: string, total: string, taxes: list<array{tax: string, kind: string, factor: string, rate: ?string, ieps_mode: ?string, position: int, base: string, amount: ?string, amount_rounded: string}>}
     */
    public function calculate(mixed $quantity, mixed $unitPrice, mixed $discount, array $specs): array
    {
        $quantity = DecimalMath::of($quantity);
        $subtotal = DecimalMath::round(DecimalMath::mul($quantity, DecimalMath::of($unitPrice)), 2);
        $discount = DecimalMath::round(DecimalMath::of($discount), 2);
        $base = DecimalMath::max(DecimalMath::sub($subtotal, $discount), '0');

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
            $rounded = $amount === null ? '0.00' : DecimalMath::round($amount, 2);

            if ($spec->isTransferredIeps() && $exact !== null) {
                $iepsAmount = DecimalMath::add($iepsAmount, $exact);
            }

            if ($spec->isWithholding()) {
                $withheld = DecimalMath::add($withheld, $rounded);
            } else {
                $transferred = DecimalMath::add($transferred, $rounded);
            }

            $rows[$position] = [
                ...$spec->toArray(),
                'position' => $position,
                'base' => DecimalMath::round($taxBase, 6),
                'amount' => $exact,
                'amount_rounded' => $rounded,
            ];
        }
        ksort($rows);

        $transferred = DecimalMath::round($transferred, 2);
        $withheld = DecimalMath::round($withheld, 2);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'base' => DecimalMath::round($base, 2),
            'tax_total' => $transferred,
            'taxes_transferred' => $transferred,
            'taxes_withheld' => $withheld,
            'total' => DecimalMath::round(DecimalMath::sub(DecimalMath::add($base, $transferred), $withheld), 2),
            'taxes' => array_values($rows),
        ];
    }
}
