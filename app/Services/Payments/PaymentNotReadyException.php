<?php

namespace App\Services\Payments;

use RuntimeException;

/** Fase 6.35: el pago no se puede emitir; `problems` dice por qué. */
class PaymentNotReadyException extends RuntimeException
{
    public const CODE = 'PAYMENT_NOT_READY';

    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('El pago no está listo para emitir su complemento de pago.');
    }
}
