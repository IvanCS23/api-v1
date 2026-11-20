<?php

namespace App\Services\Payments;

use RuntimeException;

/** Fase 6.35: ya hay una emisión en curso para el pago o para una de sus facturas. */
class PaymentIssuanceInProgressException extends RuntimeException
{
    public const CODE = 'PAYMENT_ISSUANCE_IN_PROGRESS';

    public function __construct()
    {
        parent::__construct('Ya hay una emisión de complemento de pago en curso para este pago o sus facturas. Espera unos minutos e intenta de nuevo.');
    }
}
