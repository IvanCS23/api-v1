<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Fase 6.35: el PAC devolvió el REP en un estado distinto de `valid` (o sin
 * UUID). Es ambiguo: el pago queda en `unknown` y el siguiente intento lo
 * busca por external_id.
 */
class PaymentReceiptNotValidException extends RuntimeException
{
    public const CODE = 'PAYMENT_RECEIPT_NOT_VALID';

    public function __construct(public readonly ?string $pacStatus)
    {
        parent::__construct('El proveedor fiscal no confirmó el complemento de pago como vigente. Intenta de nuevo en unos minutos.');
    }
}
