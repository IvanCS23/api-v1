<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Fase 6.35: hay una solicitud de cancelación en curso o sin respuesta clara;
 * se resuelve consultando el estado en el PAC.
 */
class PaymentCancellationInProgressException extends RuntimeException
{
    public const CODE = 'PAYMENT_CANCELLATION_IN_PROGRESS';

    public function __construct()
    {
        parent::__construct('Ya hay una solicitud de cancelación en curso o sin confirmar. Consulta el estado en el proveedor fiscal antes de intentar de nuevo.');
    }
}
