<?php

namespace App\Services\Payments;

use RuntimeException;

/** Fase 6.35: la operación requiere un REP emitido. */
class PaymentNotIssuedException extends RuntimeException
{
    public const CODE = 'PAYMENT_NOT_ISSUED';

    public function __construct()
    {
        parent::__construct('El pago no tiene un complemento de pago vigente.');
    }
}
