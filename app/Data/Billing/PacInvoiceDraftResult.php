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
 * - `status`: "draft" en el caso normal; Fase 6.2.5 confirmó que
 *   `retrieveDraftInvoice()` puede legítimamente encontrar un recurso
 *   que YA transicionó a "pending"/"valid" (si ya se timbró, por esta
 *   integración o por fuera de ella) — se acepta y se refleja tal cual,
 *   nunca se fuerza a "draft". Cualquier otro valor no reconocido sigue
 *   disparando PacUnexpectedResponseException.
 * - `isReadyToStamp`: booleano que Facturapi asigna automáticamente
 *   MIENTRAS `status === "draft"` — indica que el borrador pasa la
 *   validación MÍNIMA para timbrarse. NUNCA equivale a "CFDI timbrado"
 *   ni garantiza que el SAT vaya a aceptarlo después. Nullable (Fase
 *   6.2.5): solo tiene sentido para `status === "draft"` — para
 *   cualquier otro status, este campo puede no venir en la respuesta y
 *   eso NO es un error.
 * - `livemode`: booleano documentado en la respuesta — debe ser
 *   siempre `false` en esta fase (solo TEST); FacturapiProvider lanza
 *   PacUnexpectedEnvironmentException antes de construir este DTO si
 *   llega `true`.
 * - `idempotencyKey`/`externalReference`: ecos de `idempotency_key`/
 *   `external_id` que este ERP envió, si Facturapi los regresa.
 * - `createdAt`: el `created_at` que reporta Facturapi para el
 *   borrador (no el timestamp local de sincronización).
 * - `total`: (Fase 6.2.7) el `total` que Facturapi calculó del lado
 *   suyo para el borrador — nunca se usa para recalcular ni sobrescribir
 *   ningún importe local; solo permite comparar contra el total local
 *   (`Invoice::total`) y REPORTAR una diferencia si la hay, nunca
 *   ocultarla (ver UpdatePacDraftInvoiceService). Nullable: si Facturapi
 *   no lo incluye en la respuesta, no es un error.
 */
final class PacInvoiceDraftResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly ?bool $isReadyToStamp,
        public readonly bool $livemode,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $externalReference = null,
        public readonly ?CarbonImmutable $createdAt = null,
        public readonly ?float $total = null,
        public readonly array $rawResponse = [],
    ) {}
}
