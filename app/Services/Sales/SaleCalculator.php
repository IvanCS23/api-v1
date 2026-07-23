<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\TaxRate;

/**
 * Único responsable de calcular subtotal/descuento/impuesto/total de una
 * venta y de sus líneas. Ningún Controller, Model ni el frontend deben
 * hacer esta aritmética por su cuenta — siempre a través de esta clase.
 *
 * Wrapper delgado sobre LineItemCalculator (Fase 3 §5: la aritmética se
 * generalizó ahí para reutilizarla también en Quotes). La firma pública
 * de esta clase no cambió — nada del módulo de Ventas ni sus tests se
 * vieron afectados por el refactor.
 */
class SaleCalculator
{
    private readonly LineItemCalculator $lineItemCalculator;

    public function __construct(?LineItemCalculator $lineItemCalculator = null)
    {
        $this->lineItemCalculator = $lineItemCalculator ?? new LineItemCalculator();
    }

    /**
     * Calcula los importes de una línea de venta.
     *
     * @return array{subtotal: float, discount: float, tax_total: float, total: float}
     */
    public function calculateItem(float $quantity, float $unitPrice, float $discount = 0, ?TaxRate $taxRate = null): array
    {
        return $this->lineItemCalculator->calculateItem($quantity, $unitPrice, $discount, $taxRate);
    }

    /**
     * Recalcula los totales agregados de una venta a partir de sus
     * líneas actuales. No guarda el modelo — deja el save() a quien
     * llama, para que pueda hacerlo dentro de su propia transacción.
     */
    public function recalculateSale(Sale $sale): Sale
    {
        return $this->lineItemCalculator->recalculateTotals($sale);
    }
}
