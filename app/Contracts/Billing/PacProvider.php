<?php

namespace App\Contracts\Billing;

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

    public function cancelInvoice(
        string $externalId,
        string $motive,
        ?string $substitutionUuid = null,
    ): PacInvoiceResult;

    public function downloadPdf(string $externalId): string;

    public function downloadXml(string $externalId): string;
}
