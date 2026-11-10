<?php

namespace App\Services\Sales;

use App\Models\Quote;
use App\Models\TaxRate;
use App\Services\Taxes\TaxSpec;

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
        $this->lineItemCalculator = $lineItemCalculator ?? new LineItemCalculator;
    }

    /**
     * @return array{subtotal: float, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float}
     */
    public function calculateItem(float $quantity, float $unitPrice, float $discount = 0, ?TaxRate $taxRate = null): array
    {
        return $this->lineItemCalculator->calculateItem($quantity, $unitPrice, $discount, $taxRate);
    }

    /**
     * Fase 6.34: la línea con varios impuestos (ver LineItemCalculator::calculateLine).
     *
     * @param  list<TaxSpec>  $specs
     * @return array{subtotal: float, discount: float, tax_total: float, taxes_transferred: float, taxes_withheld: float, total: float, taxes: list<array<string, mixed>>}
     */
    public function calculateLine(mixed $quantity, mixed $unitPrice, mixed $discount, array $specs): array
    {
        return $this->lineItemCalculator->calculateLine($quantity, $unitPrice, $discount, $specs);
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
