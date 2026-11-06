<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Fase 6.31: el acuse sí corresponde al CFDI (Acuse/Folios/UUID), pero su
 * EstatusUUID no es 201 ni 202 o no viene. No se guarda: requiere revisión
 * manual. Sólo transporta el código de estatus (no es un dato sensible).
 */
class CancellationReceiptStatusRejectedException extends RuntimeException
{
    public const ERROR_CODE = 'CANCELLATION_RECEIPT_STATUS_REJECTED';

    public function __construct(
        public readonly int $invoiceId,
        public readonly string $receiptStatus,
    ) {
        parent::__construct(sprintf(
            '[%s] El acuse corresponde al CFDI de la factura [%d], pero su EstatusUUID es %s (sólo se aceptan 201 y 202).',
            self::ERROR_CODE,
            $invoiceId,
            $receiptStatus,
        ));
    }
}
