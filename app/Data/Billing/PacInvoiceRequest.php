<?php

namespace App\Data\Billing;

use App\Models\Invoice;

/**
 * Envoltura de contexto de emisión para PacProvider::createInvoice()
 * (Fase 6.2.1). Antes, `createInvoice(Invoice $invoice)` no tenía forma
 * de transmitir `idempotency_key`/`external_id` sin que el adaptador
 * los generara internamente (acoplando la generación de la llave, que
 * debe ser determinista por operación de emisión, a un detalle de
 * infraestructura) o sin recurrir a estado global/mutable.
 *
 * `idempotencyKey`/`externalId` siempre los calcula IssueInvoiceService
 * (ver su documentación) — este DTO solo los transporta hasta el
 * adaptador concreto, que los coloca en el payload saliente sin
 * interpretarlos.
 */
final class PacInvoiceRequest
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $idempotencyKey,
        public readonly string $externalId,
    ) {}
}
