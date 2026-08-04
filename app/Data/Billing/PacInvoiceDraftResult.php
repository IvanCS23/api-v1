<?php

namespace App\Data\Billing;

use Carbon\CarbonImmutable;

/**
 * Resultado normalizado de un BORRADOR (`status: "draft"`) en el PAC —
 * Fase 6.2.4. Deliberadamente NO se reutiliza PacInvoiceResult: un
 * draft no está timbrado (nunca tiene `uuid`/`stamp` reales) y mezclar
 * ambos DTOs habría hecho fácil confundir, en el código que los
 * consume, "existe un borrador" con "existe un CFDI emitido" —
 * exactamente la ambigüedad que esta fase pide evitar
 * arquitectónicamente.
 *
 * Campos confirmados contra la respuesta oficial documentada de
 * Facturapi para un Invoice (docs.facturapi.io/api/) — normalizados con
 * los nombres que mejor encajan con este proyecto:
 * - `externalId`: el `id` que Facturapi asigna al borrador.
 * - `status`: siempre "draft" cuando lo produce
 *   FacturapiProvider::mapDraftInvoiceArray() (cualquier otro valor
 *   dispara PacUnexpectedResponseException — nunca se interpreta
 *   "status=valid" como un draft correcto).
 * - `isReadyToStamp`: booleano que Facturapi asigna automáticamente —
 *   indica que el borrador pasa la validación MÍNIMA para timbrarse.
 *   NUNCA equivale a "CFDI timbrado" ni garantiza que el SAT vaya a
 *   aceptarlo después.
 * - `livemode`: booleano documentado en la respuesta — debe ser
 *   siempre `false` en esta fase (solo TEST); FacturapiProvider lanza
 *   PacUnexpectedEnvironmentException antes de construir este DTO si
 *   llega `true`.
 * - `idempotencyKey`/`externalReference`: ecos de `idempotency_key`/
 *   `external_id` que este ERP envió, si Facturapi los regresa.
 * - `createdAt`: el `created_at` que reporta Facturapi para el
 *   borrador (no el timestamp local de sincronización).
 */
final class PacInvoiceDraftResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly bool $isReadyToStamp,
        public readonly bool $livemode,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $externalReference = null,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly array $rawResponse = [],
    ) {}
}
