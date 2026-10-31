<?php

namespace App\Exceptions;

use App\Models\Invoice;
use RuntimeException;

/**
 * Fase 6.29: "Regresar a lista" no aplica a la factura en su estado actual
 * (tiene CFDI o identidad fiscal, una operación en curso, revisión
 * pendiente, o su borrador remoto ya no es un borrador). No modifica nada.
 */
class InvoiceCannotBeRevertedException extends RuntimeException
{
    public function __construct(public readonly Invoice $invoice, public readonly string $reason)
    {
        parent::__construct(sprintf(
            'La factura [%d] no puede regresar a lista: %s.',
            $invoice->id,
            $reason,
        ));
    }
}
