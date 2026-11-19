<?php

namespace App\Services\Taxes;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Collection;

/**
 * Fase 6.34: recálculo de una venta con el cálculo estilo SAT al facturarla
 * (decisión del 2026-11-19: "recalcular al facturar").
 *
 * - Cada línea se vuelve a calcular desde cantidad, valor unitario,
 *   descuento y sus impuestos (filas de impuestos, o su tasa anterior), con
 *   LineTaxCalculator; el documento, con DocumentTaxTotals.
 * - `preview()` no escribe nada: dice si cambian los totales de la venta
 *   (lo usa el readiness para avisar con ambos totales).
 * - `apply()` guarda las líneas, sus impuestos y los totales de la venta.
 *   Sólo lo llama SaleToInvoiceConverter, dentro de su transacción y con la
 *   venta bloqueada. Nunca toca facturas: las emitidas no se recalculan.
 */
class SaleTaxRecalculator
{
    public const TOTAL_COLUMNS = ['subtotal', 'discount_total', 'tax_total', 'taxes_transferred', 'taxes_withheld', 'total'];

    public function __construct(
        private readonly LineTaxCalculator $lines = new LineTaxCalculator,
        private readonly DocumentTaxTotals $documents = new DocumentTaxTotals,
        private readonly LineTaxWriter $taxWriter = new LineTaxWriter,
    ) {}

    /**
     * @return array{changed: bool, before: array<string, string>, after: array<string, string>, lines: list<array{item: SaleItem, values: array<string, mixed>}>}
     */
    public function preview(Sale $sale): array
    {
        $items = SaleItem::withoutGlobalScope(CompanyScope::class)
            ->where('sale_id', $sale->id)
            ->where('company_id', $sale->company_id)
            ->orderBy('id')
            ->get();

        $lines = [];
        $recalculated = new Collection;

        foreach ($items as $item) {
            $specs = array_map(
                fn (array $row): TaxSpec => TaxSpec::fromArray($row),
                $this->taxWriter->rowsToCopy($item, SaleItemTax::class, 'sale_item_id'),
            );
            $values = $this->lines->calculate($item->getAttributes()['quantity'], $item->getAttributes()['unit_price'], $item->getAttributes()['discount'] ?? '0', $specs);

            $copy = (new SaleItem)->forceFill(collect($values)->except(['taxes', 'base'])->all());
            $copy->setRelation('taxes', collect($values['taxes'])->map(fn (array $row) => (new SaleItemTax)->forceFill($row)));
            $recalculated->push($copy);
            $lines[] = ['item' => $item, 'values' => $values];
        }

        $after = $this->documents->compute($recalculated);
        $before = [];
        foreach (self::TOTAL_COLUMNS as $column) {
            $before[$column] = DecimalMath::round(DecimalMath::of($sale->getAttribute($column)), 2);
        }

        return ['changed' => $before !== $after, 'before' => $before, 'after' => $after, 'lines' => $lines];
    }

    /**
     * Guarda el recálculo. Devuelve el mismo resultado que preview().
     *
     * @return array{changed: bool, before: array<string, string>, after: array<string, string>, lines: list<array{item: SaleItem, values: array<string, mixed>}>}
     */
    public function apply(Sale $sale): array
    {
        $result = $this->preview($sale);

        foreach ($result['lines'] as ['item' => $item, 'values' => $values]) {
            $item->forceFill(collect($values)->only(['subtotal', 'subtotal_exact', 'discount', 'tax_total', 'taxes_transferred', 'taxes_withheld', 'total'])->all())->save();
            $this->taxWriter->replace($item, $values['taxes']);
        }

        $sale->forceFill($result['after'])->save();

        return $result;
    }
}
