<?php

namespace App\Exceptions\Billing;

use RuntimeException;

class InvoiceArtifactUnavailableException extends RuntimeException
{
    public function __construct(bool $receipt, string $extension)
    {
        $label = $receipt ? 'acuse de cancelación' : 'CFDI';

        parent::__construct(sprintf(
            'El %s %s de esta factura no está disponible.',
            strtoupper($extension),
            $label,
        ));
    }
}
