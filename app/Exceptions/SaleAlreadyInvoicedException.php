<?php

namespace App\Exceptions;

use App\Models\Sale;
use RuntimeException;

/**
 * Se lanza cuando SaleToInvoiceConverter, ya bajo lockForUpdate(),
 * detecta que la Sale ya tiene una Invoice asociada (`invoices.sale_id`
 * es único). Cubre tanto un doble intento secuencial como dos requests
 * concurrentes que pasaron ambas la validación optimista del controller
 * antes de que cualquiera adquiriera el lock — mismo patrón que
 * QuoteAlreadyConvertedException.
 */
class SaleAlreadyInvoicedException extends RuntimeException
{
    public function __construct(public readonly Sale $sale)
    {
        parent::__construct(sprintf('La venta [%d] ya tiene una factura asociada.', $sale->id));
    }
}
