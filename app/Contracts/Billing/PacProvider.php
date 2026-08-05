<?php

namespace App\Contracts\Billing;

use App\Data\Billing\PacInvoiceDraftResult;
use App\Data\Billing\PacInvoiceRequest;
use App\Data\Billing\PacInvoiceResult;

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
 *
 * `name()` (Fase 6.2.1): identificador estable y determinista del PAC
 * concreto (ej. "facturapi"), usado por IssueInvoiceService para
 * persistir `pac_provider` sin necesidad de inspeccionar la clase
 * concreta resuelta (Reflection) ni de conocer Facturapi.
 *
 * `createInvoice()` (Fase 6.2.1) recibe un PacInvoiceRequest en vez de
 * una Invoice desnuda: la Invoice sola no tenía forma de transportar
 * `idempotency_key`/`external_id` (calculados por IssueInvoiceService,
 * deterministas por operación de emisión) sin generarlos dentro del
 * adaptador ni recurrir a estado global/mutable.
 *
 * `findInvoiceByExternalId()` (Fase 6.2.2): habilita a
 * ReconcileInvoiceWithPacService a recuperar el estado real de una
 * emisión cuando `pac_external_id` (el `id` que el PAC asigna) todavía
 * no se conoce — el caso típico de `reconciliation_required` (Fase
 * 6.2.1), donde la ambigüedad ocurrió antes de recibir ese `id`. A
 * diferencia de `retrieveInvoice()` (consulta directa por `id`), esta
 * busca por el `external_id` propio que este ERP ya envió al crear. El
 * PAC NO garantiza unicidad de `external_id` — una implementación debe
 * devolver `null` si no hay coincidencias, o lanzar
 * `PacAmbiguousInvoiceMatchException` si hay más de una; nunca elegir
 * una en silencio. Cualquier paginación del proveedor concreto se
 * normaliza dentro de la implementación — el dominio nunca recibe
 * estructuras de listado/paginación específicas del PAC.
 *
 * `createDraftInvoice()`/`retrieveDraftInvoice()` (Fase 6.2.4): Facturapi
 * no documenta ningún `dry_run` para Create Invoice (confirmado contra
 * docs.facturapi.io/api/) — su mecanismo real de prevalidación es crear
 * un recurso con `status: "draft"`: se persiste de verdad en el PAC,
 * nunca se timbra, y expone `is_ready_to_stamp` (ver
 * PacInvoiceDraftResult). Deliberadamente son operaciones DISTINTAS de
 * `createInvoice()`/`retrieveInvoice()` — nunca un booleano
 * `$draft`/`$validate` agregado a esos métodos, que volvería ambiguo el
 * contrato de emisión real.
 *
 * `stampDraftInvoice()` (Fase 6.2.5): timbra un borrador YA EXISTENTE —
 * `POST /invoices/{id}/stamp` en Facturapi (confirmado contra el
 * enunciado de esta fase; ver el reporte de entrega para el detalle de
 * lo que pudo/no pudo verificarse de forma independiente en
 * docs.facturapi.io/api/, cuya sección específica de este endpoint no
 * quedó accesible en la consulta automatizada). El recurso draft SE
 * TRANSFORMA en la factura timbrada — nunca crea un segundo recurso, así
 * que reutiliza `PacInvoiceResult` (la respuesta ya es "una Invoice",
 * exactamente como `createInvoice()`/`retrieveInvoice()`), no un DTO
 * nuevo. Deliberadamente NO es un parámetro/flag agregado a
 * `createInvoice()` — timbrar un borrador y crear+timbrar de una vez son
 * operaciones distintas en el contrato oficial.
 *
 * `updateDraftInvoice()` (Fase 6.2.7): actualiza (reemplaza el payload
 * de) un borrador YA EXISTENTE — `PUT /invoices/{invoice_id}`, confirmado
 * contra docs.facturapi.io/api/ (ver el reporte de entrega de esta fase):
 * solo es posible editar una Invoice con `status: "draft"`, y si el body
 * incluye `status`, el único valor permitido sigue siendo `"draft"`
 * (Facturapi no permite cambiar el status al editar). Reutiliza
 * `PacInvoiceRequest` (ya transporta Invoice + idempotencyKey +
 * externalId, todo lo que este método necesita) y `PacInvoiceDraftResult`
 * (la respuesta es la misma forma de borrador que
 * `createDraftInvoice()`/`retrieveDraftInvoice()`) — ningún DTO nuevo.
 * Nunca crea un recurso nuevo: el `$externalId` de entrada identifica el
 * MISMO borrador, y una implementación debe exigir que la respuesta
 * confirme ese mismo `id`.
 */
interface PacProvider
{
    public function name(): string;

    public function createInvoice(PacInvoiceRequest $request): PacInvoiceResult;

    public function retrieveInvoice(string $externalId): PacInvoiceResult;

    /**
     * @throws \App\Exceptions\Billing\PacAmbiguousInvoiceMatchException si el PAC devuelve más de una coincidencia
     */
    public function findInvoiceByExternalId(string $externalId): ?PacInvoiceResult;

    /**
     * Crea un borrador (`status: "draft"`) — un recurso REAL y
     * persistente del lado del PAC, nunca timbrado.
     *
     * @throws \App\Exceptions\Billing\PacUnexpectedEnvironmentException si el PAC responde con livemode=true
     */
    public function createDraftInvoice(PacInvoiceRequest $request): PacInvoiceDraftResult;

    /**
     * @throws \App\Exceptions\Billing\PacUnexpectedEnvironmentException si el PAC responde con livemode=true
     */
    public function retrieveDraftInvoice(string $externalId): PacInvoiceDraftResult;

    /**
     * Actualiza el borrador identificado por `$externalId` con el
     * payload fiscal actual — nunca crea un segundo recurso. Solo válido
     * mientras el borrador siga en `status: "draft"` del lado del PAC.
     *
     * @throws \App\Exceptions\Billing\PacUnexpectedEnvironmentException si el PAC responde con livemode=true
     * @throws \App\Exceptions\Billing\PacUnexpectedResponseException si la respuesta confirma un `id` distinto de `$externalId`
     */
    public function updateDraftInvoice(string $externalId, PacInvoiceRequest $request): PacInvoiceDraftResult;

    /**
     * Timbra el borrador identificado por `$externalId` (el `id` que el
     * PAC le asignó al crearlo) — nunca crea un CFDI nuevo mediante
     * `createInvoice()`.
     *
     * @throws \App\Exceptions\Billing\PacUnexpectedEnvironmentException si el PAC responde con livemode=true
     * @throws \App\Exceptions\Billing\PacConflictException si el PAC responde 409 (ambiguo — nunca reintentar a ciegas)
     */
    public function stampDraftInvoice(string $externalId): PacInvoiceResult;

    public function cancelInvoice(
        string $externalId,
        string $motive,
        ?string $substitutionUuid = null,
    ): PacInvoiceResult;

    public function downloadPdf(string $externalId): string;

    public function downloadXml(string $externalId): string;
}
