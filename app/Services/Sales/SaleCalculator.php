<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\TaxRate;
use App\Services\Taxes\TaxSpec;

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
        $this->lineItemCalculator = $lineItemCalculator ?? new LineItemCalculator;
    }

    /**
     * Calcula los importes de una línea de venta.
     *
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
     * Recalcula los totales agregados de una venta a partir de sus
     * líneas actuales. No guarda el modelo — deja el save() a quien
     * llama, para que pueda hacerlo dentro de su propia transacción.
     */
    public function recalculateSale(Sale $sale): Sale
    {
        return $this->lineItemCalculator->recalculateTotals($sale);
    }
}
