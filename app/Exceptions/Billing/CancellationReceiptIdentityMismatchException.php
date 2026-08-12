<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * El UUID fiscal definido por Acuse/Folios/UUID no identifica al CFDI
 * local esperado. No transporta ni publica los UUID encontrados.
 */
class CancellationReceiptIdentityMismatchException extends RuntimeException
{
    public const ERROR_CODE = 'CANCELLATION_RECEIPT_UUID_MISMATCH';

    public function __construct(
        public readonly int $invoiceId,
        public readonly int $receiptUuidCount,
    ) {
        parent::__construct(sprintf(
            '[%s] El UUID fiscal identificado en el acuse no corresponde al CFDI local para la factura [%d].',
            self::ERROR_CODE,
            $invoiceId,
        ));
    }
}
