<?php

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Se lanza por FacturapiProvider::findInvoiceByExternalId() cuando el
 * endpoint de listado de Facturapi (GET /v2/invoices?external_id=...)
 * devuelve más de un resultado para el mismo `external_id` (Fase 6.2.2).
 *
 * La documentación oficial no garantiza unicidad de `external_id` — a
 * diferencia de `id`/`uuid`, que sí son únicos del lado del PAC. Ante
 * varios resultados, nunca se elige silenciosamente el primero (podría
 * persistir el resultado de una factura equivocada): se detiene y deja
 * la ambigüedad para revisión — ver ReconcileInvoiceWithPacService, que
 * mantiene `pac_reconciliation_required=true` ante esta excepción.
 */
class PacAmbiguousInvoiceMatchException extends RuntimeException
{
    public function __construct(public readonly string $externalId, public readonly int $matchCount)
    {
        parent::__construct(sprintf(
            'El PAC devolvió %d facturas para external_id "%s"; no se garantiza unicidad de external_id, así que no se puede elegir una automáticamente.',
            $matchCount,
            $externalId,
        ));
    }
}
