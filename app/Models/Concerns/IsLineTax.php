<?php

namespace App\Models\Concerns;

use App\Enums\TaxFactorType;
use App\Enums\TaxType;

/**
 * Fase 6.34: atributos comunes de un impuesto de producto o de línea
 * (product_taxes, quote_item_taxes, sale_item_taxes, invoice_item_taxes).
 *
 * - `tax`: clave de c_Impuesto (001 ISR, 002 IVA, 003 IEPS).
 * - `kind`: traslado o retención. `factor`: tasa, cuota o exento.
 * - `rate`: c_TasaOCuota con 6 decimales; nulo si es exento.
 */
trait IsLineTax
{
    public const TAX_ISR = '001';

    public const TAX_IVA = '002';

    public const TAX_IEPS = '003';

    public function initializeIsLineTax(): void
    {
        $this->mergeFillable(['tax', 'kind', 'factor', 'rate', 'ieps_mode', 'position']);
        $this->mergeCasts([
            'kind' => TaxType::class,
            'factor' => TaxFactorType::class,
            'rate' => 'decimal:6',
            'position' => 'integer',
        ]);

        if ($this->hasAmounts()) {
            $this->mergeFillable(['base', 'amount', 'amount_rounded']);
            $this->mergeCasts(['base' => 'decimal:6', 'amount' => 'decimal:6', 'amount_rounded' => 'decimal:2']);
        }
    }

    protected function hasAmounts(): bool
    {
        return true;
    }

    public function isWithholding(): bool
    {
        return $this->kind === TaxType::Retencion;
    }
}
