<?php

namespace App\Services\Payments;

use RuntimeException;

/** Fase 6.35: sólo un pago en borrador (sin REP emitido) se edita o elimina. */
class PaymentNotDraftException extends RuntimeException
{
    public const CODE = 'PAYMENT_NOT_DRAFT';

    public function __construct()
    {
        parent::__construct('Sólo un pago en borrador se puede editar o eliminar; este ya tiene su complemento de pago.');
    }
}
