<?php

namespace App\Support\Billing;

/**
 * Única fuente de verdad para las dos llaves deterministas que
 * IssueInvoiceService calcula al reservar una emisión y que
 * ReconcileInvoiceWithPacService debe poder RECONSTRUIR de forma
 * idéntica cuando `pac_external_id` todavía no se conoce (Fase 6.2.2,
 * ver ReconcileInvoiceWithPacService::reconcile()). Extraída como
 * helper compartido en vez de duplicar la fórmula en dos servicios —
 * así ambos quedan garantizados a producir siempre el mismo valor para
 * la misma Invoice, sin depender de mantener dos copias sincronizadas.
 *
 * Funciones puras de (company_id, invoice_id): nunca aleatorias, nunca
 * dependen de datos fiscales sensibles ni de `pac_external_id` (que no
 * existe todavía antes de emitir).
 */
final class PacIdentifiers
{
    public static function idempotencyKey(int $companyId, int $invoiceId): string
    {
        return sprintf('erp-invoice:%d:%d:v1', $companyId, $invoiceId);
    }

    public static function externalId(int $companyId, int $invoiceId): string
    {
        return sprintf('company-%d-invoice-%d', $companyId, $invoiceId);
    }

    /**
     * Llave de idempotencia del BORRADOR (Fase 6.2.4) — deliberadamente
     * distinta de idempotencyKey(): un draft es otro recurso/operación
     * en el PAC, no la emisión final. Reutilizar la misma llave
     * arriesgaría que Facturapi tratara ambas operaciones (crear el
     * borrador y, después, la emisión real) como el mismo intento.
     * Determinista/estable — un reintento de "crear el mismo draft"
     * siempre recalcula esta misma llave.
     */
    public static function draftIdempotencyKey(int $companyId, int $invoiceId): string
    {
        return sprintf('erp-invoice-draft:%d:%d:v1', $companyId, $invoiceId);
    }

    /**
     * `external_id` del BORRADOR (Fase 6.2.4) — también distinto de
     * externalId(): si el draft y la eventual emisión final compartieran
     * el mismo external_id, ambos recursos coexistirían en Facturapi con
     * el mismo valor, y PacProvider::findInvoiceByExternalId() (Fase
     * 6.2.2) los reportaría como coincidencia múltiple
     * (PacAmbiguousInvoiceMatchException) al intentar reconciliar la
     * emisión real — un falso positivo evitable con un sufijo propio.
     */
    public static function draftExternalId(int $companyId, int $invoiceId): string
    {
        return sprintf('company-%d-invoice-%d-draft', $companyId, $invoiceId);
    }
}
