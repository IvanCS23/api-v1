<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\TaxRate;

/**
 * Único responsable de calcular subtotal/descuento/impuesto/total de una
 * cotización y de sus líneas. Wrapper delgado sobre LineItemCalculator
 * — misma aritmética exacta que SaleCalculator, reutilizada (Fase 3 §5).
 */
class QuoteCalculator
{
    private readonly LineItemCalculator $lineItemCalculator;

    public function __construct(?LineItemCalculator $lineItemCalculator = null)
    {
        $this->lineItemCalculator = $lineItemCalculator ?? new LineItemCalculator();
    }

    /**
     * @return array{subtotal: float, discount: float, tax_total: float, total: float}
     */
    public function calculateItem(float $quantity, float $unitPrice, float $discount = 0, ?TaxRate $taxRate = null): array
    {
        return $this->lineItemCalculator->calculateItem($quantity, $unitPrice, $discount, $taxRate);
    }

    /**
     * Recalcula los totales agregados de una cotización a partir de sus
     * líneas actuales. No guarda el modelo.
     */
    public function recalculateQuote(Quote $quote): Quote
    {
        return $this->lineItemCalculator->recalculateTotals($quote);
    }
}
