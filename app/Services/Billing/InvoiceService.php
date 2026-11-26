<?php

namespace App\Services\Billing;

use App\Exceptions\InvoiceNotEditableException;
use App\Models\Invoice;

/**
 * Lógica de negocio de edición de facturas. La creación vive en
 * SaleToInvoiceConverter y las transiciones en InvoiceWorkflow.
 *
 * La autorización (policies) NO se hace aquí: es responsabilidad de la
 * capa HTTP.
 */
class InvoiceService
{
    /**
     * Solo una factura editable (según Invoice::isEditable) acepta cambios.
     *
     * @param  array<string, mixed>  $data  Datos validados.
     *
     * @throws InvoiceNotEditableException
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        if (! $invoice->isEditable()) {
            throw InvoiceNotEditableException::forUpdate($invoice);
        }

        $invoice->update($data);

        return $invoice->load('items');
    }
}
