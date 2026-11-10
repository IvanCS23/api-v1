<?php

namespace App\Services\Sales;

use App\Models\TaxRate;
use App\Services\Taxes\LineTaxCalculator;
use App\Services\Taxes\TaxSpec;
use Illuminate\Database\Eloquent\Model;

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
     * @return array{subtotal: float, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float}
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
     * @return array{subtotal: float, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float}
     */
    private function columns(array $result): array
    {
        return [
            'subtotal' => (float) $result['subtotal'],
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
        $items = $model->items()->get();

        $model->forceFill([
            'subtotal' => round((float) $items->sum('subtotal'), 2),
            'discount_total' => round((float) $items->sum('discount'), 2),
            'tax_total' => round((float) $items->sum('tax_total'), 2),
            'taxes_transferred' => round((float) $items->sum('taxes_transferred'), 2),
            'taxes_withheld' => round((float) $items->sum('taxes_withheld'), 2),
            'total' => round((float) $items->sum('total'), 2),
        ]);

        return $model;
    }
}
