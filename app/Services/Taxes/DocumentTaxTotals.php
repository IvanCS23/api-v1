<?php

namespace App\Services\Taxes;

use App\Enums\TaxFactorType;
use App\Models\InvoiceItem;
use App\Models\InvoiceItemTax;
use App\Models\QuoteItem;
use App\Models\QuoteItemTax;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 6.34 (cálculo estilo SAT): totales de un documento (cotización,
 * venta o factura) a partir de sus líneas, como el resumen del CFDI y como
 * los calcula Facturapi (paso 7, caso H):
 *
 * - subtotal = redondeo a 2 de la suma de los importes exactos de los
 *   conceptos (`subtotal_exact`; una línea anterior sin él usa `subtotal`);
 * - descuento = suma de los descuentos;
 * - cada grupo de impuesto (impuesto, traslado/retención, factor, tasa) =
 *   redondeo a 2 de la suma de sus importes exactos (guía de llenado,
 *   págs. 24–26); trasladados y retenidos = suma de sus grupos;
 * - total = subtotal − descuento + trasladados − retenidos.
 * - `tax_total` sigue siendo la suma de los trasladados.
 *
 * Por eso la suma de las líneas redondeadas puede diferir del total por
 * centavos. Es la única fuente de los totales del documento: la usan el
 * recálculo, SaleWorkflow, el readiness de la venta y
 * billing:tax-lines-check.
 */
class DocumentTaxTotals
{
    /** @var array<class-string, array{0: class-string<Model>, 1: string}> */
    private const TAX_MODELS = [
        QuoteItem::class => [QuoteItemTax::class, 'quote_item_id'],
        SaleItem::class => [SaleItemTax::class, 'sale_item_id'],
        InvoiceItem::class => [InvoiceItemTax::class, 'invoice_item_id'],
    ];

    public function __construct(private readonly LineTaxWriter $taxWriter = new LineTaxWriter) {}

    /**
     * @param  iterable<Model>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, taxes_transferred: string, taxes_withheld: string, total: string}
     */
    public function compute(iterable $lines): array
    {
        $importe = '0';
        $discount = '0';
        $groups = [];

        foreach ($lines as $line) {
            $exact = $line->getAttribute('subtotal_exact');
            $importe = DecimalMath::add($importe, DecimalMath::of($exact ?? $line->getAttribute('subtotal')));
            $discount = DecimalMath::add($discount, DecimalMath::of($line->getAttribute('discount')));

            $rows = $this->taxRows($line);

            // Una línea sin filas de impuestos ni tasa (anterior a la 6.34,
            // creada a mano) cuenta sus columnas tal cual, ya redondeadas.
            if ($rows === []) {
                foreach (['traslado' => 'tax_total', 'retencion' => 'taxes_withheld'] as $kind => $column) {
                    $value = DecimalMath::of($line->getAttribute($column));
                    if (bccomp($value, '0', 6) !== 0) {
                        $groups["legacy|{$kind}|"] = DecimalMath::add($groups["legacy|{$kind}|"] ?? '0', $value);
                    }
                }
            }

            foreach ($rows as $tax) {
                if (($tax['factor'] instanceof TaxFactorType ? $tax['factor']->value : $tax['factor']) === TaxFactorType::Exento->value) {
                    continue;
                }
                $kind = $tax['kind'] instanceof \BackedEnum ? $tax['kind']->value : (string) $tax['kind'];
                $key = implode('|', [$tax['tax'], $kind, $tax['factor'] instanceof \BackedEnum ? $tax['factor']->value : $tax['factor'], DecimalMath::round(DecimalMath::of($tax['rate']), 6)]);
                $amount = $tax['amount'] ?? $tax['amount_rounded'] ?? '0';
                $groups[$key] = DecimalMath::add($groups[$key] ?? '0', DecimalMath::of($amount));
            }
        }

        $transferred = '0';
        $withheld = '0';
        foreach ($groups as $key => $sum) {
            $rounded = DecimalMath::round($sum, 2);
            str_contains($key, '|retencion|')
                ? $withheld = DecimalMath::add($withheld, $rounded)
                : $transferred = DecimalMath::add($transferred, $rounded);
        }

        $subtotal = DecimalMath::round($importe, 2);
        $discountTotal = DecimalMath::round($discount, 2);
        $transferred = DecimalMath::round($transferred, 2);
        $withheld = DecimalMath::round($withheld, 2);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_total' => $transferred,
            'taxes_transferred' => $transferred,
            'taxes_withheld' => $withheld,
            'total' => DecimalMath::round(DecimalMath::sub(DecimalMath::add(DecimalMath::sub($subtotal, $discountTotal), $transferred), $withheld), 2),
        ];
    }

    /**
     * ¿Los totales guardados del documento coinciden con los calculados?
     * Por omisión, las columnas de antes de la 6.34 (el total ya incluye las
     * retenciones); billing:tax-lines-check revisa además trasladados y
     * retenidos.
     *
     * @param  array<string, string>  $computed
     * @param  list<string>  $columns
     */
    public static function matches(Model $document, array $computed, array $columns = ['subtotal', 'discount_total', 'tax_total', 'total']): bool
    {
        foreach ($columns as $column) {
            if (DecimalMath::round(DecimalMath::of($document->getAttribute($column)), 2) !== $computed[$column]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Las filas de impuestos de la línea (o, si es anterior a la 6.34 y no
     * tiene, su tasa única con las cifras guardadas).
     *
     * @return list<array<string, mixed>>
     */
    private function taxRows(Model $line): array
    {
        if ($line->relationLoaded('taxes') && $line->getRelation('taxes')->isNotEmpty()) {
            return $line->getRelation('taxes')->map(fn (Model $tax): array => [
                'tax' => $tax->getAttribute('tax'),
                'kind' => $tax->getAttribute('kind'),
                'factor' => $tax->getAttribute('factor'),
                // Atributos actuales sin cast (sirven también en un modelo sin guardar).
                'rate' => $tax->getAttributes()['rate'] ?? null,
                'amount' => $tax->getAttributes()['amount'] ?? null,
                'amount_rounded' => $tax->getAttributes()['amount_rounded'] ?? null,
            ])->all();
        }

        [$taxModel, $foreignKey] = self::TAX_MODELS[$line::class];

        return $this->taxWriter->rowsToCopy($line, $taxModel, $foreignKey);
    }
}
