<?php

namespace App\Exceptions;

use App\Models\Sale;
use RuntimeException;

/** Fase 6.25: una venta cancelada no puede facturarse (409 SALE_CANCELLED). */
class SaleCancelledException extends RuntimeException
{
    public function __construct(public readonly Sale $sale)
    {
        parent::__construct(sprintf('La venta %s está cancelada y no puede facturarse.', $sale->folio));
    }
}
