<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\QuoteItem;
use App\Models\QuoteItemTax;
use App\Models\SaleItem;
use App\Models\SaleItemTax;
use App\Models\TaxRate;
use App\Services\Taxes\DocumentTaxTotals;
use App\Services\Taxes\LineTaxCalculator;
use App\Services\Taxes\LineTaxWriter;
use App\Services\Taxes\TaxSpec;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Motor de cálculo genérico compartido entre Sales y Quotes (Fase 3):
 * único lugar que hace la aritmética de subtotal/descuento/impuesto/
 * total, tanto por línea como el agregado de un documento completo.
 *
 * Nace del refactor invitado en Fase 3 §5: la lógica de SaleCalculator
 * era 100% genérica (no dependía de nada específico de Sale salvo el
 * tipo del parámetro), así que se extrajo aquí. `SaleCalculator` y
 * `QuoteCalculator` son wrappers delgados sobre esta clase — sus firmas
 * públicas (`calculateItem()`, `recalculateSale()`/`recalculateQuote()`)
 * no cambiaron, así que el módulo de Ventas sigue funcionando igual.
 */
class LineItemCalculator
{
    public function __construct(private readonly LineTaxCalculator $taxes = new LineTaxCalculator) {}

    /**
     * Fase 6.34 (3): delega en LineTaxCalculator (bcmath). Una tasa de
     * retención ya no se suma como traslado: va a `taxes_withheld` y se
     * resta del total. `tax_total` sigue siendo la suma de los trasladados.
     *
     * @return array{subtotal: float, subtotal_exact: string, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float}
     */
    public function calculateItem(float $quantity, float $unitPrice, float $discount = 0, ?TaxRate $taxRate = null): array
    {
        return $this->columns($this->taxes->calculate($quantity, $unitPrice, $discount, $taxRate !== null ? [TaxSpec::fromTaxRate($taxRate)] : []));
    }

    /**
     * Fase 6.34: la línea con varios impuestos. Devuelve las columnas de la
     * línea y, en `taxes`, las filas de impuestos por línea.
     *
     * @param  list<TaxSpec>  $specs
     * @return array{subtotal: float, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float, taxes: list<array<string, mixed>>}
     */
    public function calculateLine(mixed $quantity, mixed $unitPrice, mixed $discount, array $specs): array
    {
        $result = $this->taxes->calculate($quantity, $unitPrice, $discount, $specs);

        return [...$this->columns($result), 'taxes' => $result['taxes']];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{subtotal: float, subtotal_exact: string, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float}
     */
    private function columns(array $result): array
    {
        return [
            'subtotal' => (float) $result['subtotal'],
            'subtotal_exact' => $result['subtotal_exact'],
            'discount' => (float) $result['discount'],
            'tax_total' => (float) $result['tax_total'],
            'taxes_transferred' => (float) $result['taxes_transferred'],
            'taxes_withheld' => (float) $result['taxes_withheld'],
            'total' => (float) $result['total'],
        ];
    }

    /**
     * Recalcula los totales agregados de un documento (Sale, Quote...) a
     * partir de sus líneas actuales. No guarda el modelo — deja el
     * save() a quien llama.
     */
    public function recalculateTotals(Model&HasCalculableItems $model): Model&HasCalculableItems
    {
        // Fase 6.34: las facturas nunca se recalculan (su snapshot es el CFDI).
        if ($model instanceof Invoice) {
            throw new LogicException('Las facturas no se recalculan: sus importes son el snapshot fiscal.');
        }

        // Fase 6.34: una línea anterior sin importe exacto se recalcula desde
        // sus datos exactos, igual que una línea nueva, antes de agregar.
        $this->recalculateLegacyLines($model->items()->get());

        // Estilo SAT (DocumentTaxTotals), no la suma de las líneas redondeadas.
        $model->forceFill((new DocumentTaxTotals)->compute($model->items()->get()));

        return $model;
    }

    /**
     * Fase 6.34: líneas de cotización o venta sin `subtotal_exact` (anteriores
     * al cálculo estilo SAT): se recalculan desde cantidad × valor unitario −
     * descuento y sus impuestos, y se guardan con sus filas de impuestos.
     *
     * @param  iterable<Model>  $items
     */
    private function recalculateLegacyLines(iterable $items): void
    {
        $writer = new LineTaxWriter;

        foreach ($items as $item) {
            if ($item->getAttribute('subtotal_exact') !== null) {
                continue;
            }

            [$taxModel, $foreignKey] = match ($item::class) {
                QuoteItem::class => [QuoteItemTax::class, 'quote_item_id'],
                SaleItem::class => [SaleItemTax::class, 'sale_item_id'],
                default => throw new LogicException('Sólo se recalculan líneas de cotización o de venta.'),
            };

            $specs = array_map(fn (array $row): TaxSpec => TaxSpec::fromArray($row), $writer->rowsToCopy($item, $taxModel, $foreignKey));
            $attributes = $item->getAttributes();
            $values = $this->taxes->calculate($attributes['quantity'], $attributes['unit_price'], $attributes['discount'] ?? '0', $specs);

            $item->forceFill(collect($values)->only(['subtotal', 'subtotal_exact', 'discount', 'tax_total', 'taxes_transferred', 'taxes_withheld', 'total'])->all())->save();
            $writer->replace($item, $values['taxes']);
        }
    }
}
