<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Fase 6.34 (6): el total del borrador en el PAC no coincide con el total
 * local de la factura. Se bloquea el timbrado: nunca se timbra un CFDI con
 * importes distintos a los del sistema ni se recalcula para forzar la
 * coincidencia. Tolerancia: PacTotalTolerance.
 */
class InvoicePacTotalMismatchException extends RuntimeException
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $localTotal,
        public readonly string $remoteTotal,
    ) {
        parent::__construct(sprintf(
            'La factura [%d] tiene total local %s y el borrador del PAC %s; no se timbra.',
            $invoice->id,
            $localTotal,
            $remoteTotal,
        ));
    }
}
