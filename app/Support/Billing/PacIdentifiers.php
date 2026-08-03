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
}
