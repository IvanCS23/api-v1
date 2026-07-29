<?php

namespace App\Contracts\Billing;

use App\Data\Billing\PacInvoiceResult;
use App\Models\Invoice;

/**
 * Contrato de integración con un PAC (Proveedor Autorizado de
 * Certificación), independiente de cualquier SDK concreto. Fase 6.1:
 * define la forma, no implementa timbrado real — ninguna implementación
 * de este contrato debe ser invocada todavía desde un endpoint público.
 *
 * Deliberadamente NO importa nada del SDK de Facturapi (ni de ningún
 * otro PAC): así, cambiar de proveedor implica escribir una nueva clase
 * que implemente este contrato, sin tocar `Invoice`, `InvoiceWorkflow`
 * ni `SaleToInvoiceConverter`.
 */
interface PacProvider
{
    public function createInvoice(Invoice $invoice): PacInvoiceResult;

    public function retrieveInvoice(string $externalId): PacInvoiceResult;

    public function cancelInvoice(
        string $externalId,
        string $motive,
        ?string $substitutionUuid = null,
    ): PacInvoiceResult;

    public function downloadPdf(string $externalId): string;

    public function downloadXml(string $externalId): string;
}
