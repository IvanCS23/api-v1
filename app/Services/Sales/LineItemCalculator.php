<?php

namespace App\Services\Sales;

use App\Models\TaxRate;
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
    /**
     * @return array{subtotal: float, discount: float, tax_total: float, total: float}
     */
    public function calculateItem(float $quantity, float $unitPrice, float $discount = 0, ?TaxRate $taxRate = null): array
    {
        $subtotal = round($quantity * $unitPrice, 2);
        $taxableBase = max($subtotal - $discount, 0);
        $taxTotal = $taxRate !== null ? round($taxableBase * (float) $taxRate->rate, 2) : 0.0;
        $total = round($taxableBase + $taxTotal, 2);

        return [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_total' => $taxTotal,
            'total' => $total,
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
            'total' => round((float) $items->sum('total'), 2),
        ]);

        return $model;
    }
}
