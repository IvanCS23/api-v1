<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * La DB declara el acuse como almacenado, pero falta un archivo o su hash
 * ya no coincide. La metadata historica se conserva para investigacion.
 */
class CancellationReceiptArtifactMissingException extends RuntimeException
{
    public function __construct(public readonly int $invoiceId)
    {
        parent::__construct(sprintf(
            'La factura [%d] tiene un acuse marcado como almacenado, pero falta un archivo o su hash no coincide; requiere reconciliacion manual.',
            $invoiceId,
        ));
    }
}
