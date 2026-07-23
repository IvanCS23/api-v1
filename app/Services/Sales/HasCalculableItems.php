<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contrato mínimo que necesita LineItemCalculator::recalculateTotals()
 * para recalcular subtotal/discount_total/tax_total/total a partir de
 * las líneas de un documento comercial (Sale, Quote...). Cualquier
 * modelo con esas 4 columnas + una relación items() puede implementarlo.
 */
interface HasCalculableItems
{
    public function items(): HasMany;
}
