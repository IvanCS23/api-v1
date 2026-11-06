<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Fase 6.31: operación histórica que no se ofrece a una factura Live (p. ej.
 * la emisión directa sin borrador de IssueInvoiceService). Se lanza antes de
 * reservar nada o de contactar al PAC.
 */
final class LegacyOperationUnavailableException extends RuntimeException
{
    public function __construct(public readonly int $invoiceId, string $operation)
    {
        parent::__construct(sprintf('La operación histórica "%s" no está disponible para la factura Live [%d].', $operation, $invoiceId));
    }
}
