<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Fase 6.28: la organización de Facturapi ya tiene otro comprobante
 * (timbrado, cancelado o borrador ajeno a esta factura) con la misma serie
 * y folio. Facturapi no documenta que rechace folios duplicados, así que
 * se detiene antes de crear o actualizar el borrador: no se escribe nada
 * ni en el PAC ni en la factura.
 */
class PacFolioAlreadyUsedException extends RuntimeException
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly string $cfdiFolio,
        public readonly int $matches,
    ) {
        parent::__construct(sprintf(
            'La factura [%d] no se envía al PAC: el folio %s ya está en %d comprobante(s) de la organización que no son de esta factura.',
            $invoiceId,
            $cfdiFolio,
            $matches,
        ));
    }
}
