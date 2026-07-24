<?php

namespace App\Exceptions;

use App\Models\Quote;
use RuntimeException;

/**
 * Se lanza cuando QuoteToSaleConverter, ya bajo lockForUpdate(), detecta
 * que la Quote no está en condiciones de convertirse (no está Approved, o
 * ya tiene un converted_sale_id). Cubre tanto el caso de un doble intento
 * secuencial como el de dos requests concurrentes que pasaron ambas la
 * validación optimista del controller antes de que cualquiera adquiriera
 * el lock.
 */
class QuoteAlreadyConvertedException extends RuntimeException
{
    public function __construct(public readonly Quote $quote)
    {
        parent::__construct(sprintf(
            'La cotización [%d] ya no está disponible para conversión (status actual: %s, converted_sale_id: %s).',
            $quote->id,
            $quote->status->value,
            $quote->converted_sale_id !== null ? (string) $quote->converted_sale_id : 'null',
        ));
    }
}
