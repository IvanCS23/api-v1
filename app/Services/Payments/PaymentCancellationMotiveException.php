<?php

namespace App\Services\Payments;

use RuntimeException;

/** Fase 6.35 (ajuste 2): un REP sólo se cancela con los motivos 02 y 03. */
class PaymentCancellationMotiveException extends RuntimeException
{
    public const CODE = 'CANCELLATION_MOTIVE_UNAVAILABLE';

    public function __construct(public readonly string $motive)
    {
        parent::__construct('Un complemento de pago sólo se cancela con los motivos 02 (errores sin relación) o 03 (no se llevó a cabo la operación).');
    }
}
