<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Se lanza por FacturapiProvider antes de construir/enviar el payload de
 * timbrado (nunca después), cuando el snapshot fiscal de la
 * Invoice/InvoiceItem no trae uno o más datos obligatorios del contrato
 * oficial de creación de facturas de Facturapi (docs.facturapi.io/api/,
 * auditado en Fase 6.2.1). Nunca se envían nulls ni defaults fiscales
 * inventados al PAC — si algo obligatorio falta, la emisión se detiene
 * aquí, antes de cualquier llamada HTTP.
 *
 * `missingFields` lista únicamente NOMBRES de columnas/campos, nunca
 * valores fiscales — es seguro incluirlo en logs/`pac_last_error`.
 */
class InvoiceFiscalSnapshotIncompleteException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingFields
     */
    public function __construct(public readonly int $invoiceId, public readonly array $missingFields)
    {
        parent::__construct(sprintf(
            'La factura [%d] no puede emitirse: faltan campos obligatorios del contrato de Facturapi en el snapshot fiscal: %s.',
            $invoiceId,
            implode(', ', $missingFields),
        ));
    }
}
