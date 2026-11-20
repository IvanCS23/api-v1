<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Fase 6.35 (D3): el cálculo local de un documento relacionado no coincide
 * con el payment-summary del PAC; no se emite el REP.
 */
class PaymentSummaryMismatchException extends RuntimeException
{
    public const CODE = 'PAYMENT_SUMMARY_MISMATCH';

    /** @param  list<array{invoice: string, field: string, local: mixed, pac: mixed}>  $differences */
    public function __construct(public readonly array $differences)
    {
        parent::__construct('El cálculo del complemento de pago no coincide con el del proveedor fiscal; no se emitió. Revisa el detalle.');
    }
}
