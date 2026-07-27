<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando SaleToInvoiceConverter intenta convertir una Sale cuyo
 * SaleBillingReadinessService::evaluate() devolvió `ready=false`. Carga
 * el array `errors` tal cual lo produjo el servicio para que el
 * controller pueda exponerlo en la respuesta 422 sin recalcularlo.
 */
class SaleNotBillableException extends RuntimeException
{
    /**
     * @param  array<int, array{code: string, field: string, message: string}>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('La venta no está lista para facturarse.');
    }
}
