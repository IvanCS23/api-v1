<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\Billing\PacAmbiguousInvoiceMatchException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Recuperación (Fase 6.2.1, funcional desde Fase 6.2.2) para Invoices
 * marcadas `pac_reconciliation_required=true` por IssueInvoiceService o
 * StampPacDraftInvoiceService (Fase 6.2.5), tras una respuesta ambigua
 * del PAC (timeout, conexión interrumpida, 5xx, 409, respuesta no
 * parseable) donde no se sabe con certeza si Facturapi llegó a
 * crear/timbrar la factura. Nunca vuelve a emitir (nunca llama
 * `createInvoice()`/`stampDraftInvoice()`) ni crea una factura nueva —
 * solo intenta AVERIGUAR el estado real y sincronizarlo.
 *
 * Aislamiento del PAC (sin cambios desde Fase 6.2.1): esta clase solo
 * conoce `PacProvider` (contrato) y sus DTOs/excepciones — nunca
 * `FacturapiProvider`, URLs, el facade `Http`, el Bearer token, ni la
 * forma JSON específica de un proveedor concreto.
 *
 * Flujo (Fase 6.2.5 agrega una tercera fuente de identificador — ver
 * §7/§14 del reporte de entrega de esa fase):
 *   1. valida tenant;
 *   2. si `pac_external_id` es conocido -> `retrieveInvoice()`;
 *   3. si no, pero `pac_draft_external_id` es conocido (el draft pudo
 *      haberse timbrado por esta integración o por fuera de ella) ->
 *      `retrieveInvoice(pac_draft_external_id)` — el mismo recurso
 *      remoto, consultado con su forma completa de Invoice (con `uuid`),
 *      no con la forma de borrador;
 *   4. si tampoco -> reconstruye `external_id` determinista de emisión
 *      directa con PacIdentifiers y usa `findInvoiceByExternalId()`;
 *   5. encontrada -> persiste transaccionalmente; `pac_issue_status`
 *      solo pasa a `succeeded` si `status === "valid"` y trae `uuid` —
 *      cualquier otro caso (ej. "pending") se conserva como ambiguo, sin
 *      inventar un éxito que la respuesta no confirma; dispara
 *      `InvoiceIssued` tras el commit ÚNICAMENTE si `cfdi_uuid` pasó de
 *      null a un valor real en ESTA operación (nunca dos veces — ver
 *      Fase 6.2.5 §15);
 *   6. no encontrada (null) -> mantiene reconciliation_required=true,
 *      log sanitizado, sin crear nada;
 *   7. ambigua (>1 coincidencia) o error de red/5xx/409 -> mantiene
 *      reconciliation_required=true, nunca modifica datos PAC válidos
 *      ya existentes, log sanitizado (nunca secretos ni respuesta
 *      completa).
 */
class ReconcileInvoiceWithPacService
{
    public function __construct(private readonly PacProvider $pacProvider) {}

    public function reconcile(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        if ($current->pac_idempotency_key === null && $current->pac_draft_idempotency_key === null) {
            throw new RuntimeException(sprintf(
                'La factura [%d] no tiene contexto de emisión ni de borrador ante el PAC; no hay nada que reconciliar.',
                $current->id,
            ));
        }

        $startedAt = microtime(true);

        try {
            $result = match (true) {
                $current->pac_external_id !== null => $this->pacProvider->retrieveInvoice($current->pac_external_id),
                $current->pac_draft_external_id !== null => $this->pacProvider->retrieveInvoice($current->pac_draft_external_id),
                default => $this->pacProvider->findInvoiceByExternalId(
                    PacIdentifiers::externalId($current->company_id, $current->id),
                ),
            };
        } catch (PacAmbiguousInvoiceMatchException $e) {
            // Facturapi no garantiza unicidad de external_id — nunca se
            // elige una coincidencia en silencio. Se deja
            // reconciliation_required=true para revisión manual.
            $this->recordReconciliationFailure($current, $e, 'billing.invoice.pac_reconciliation_ambiguous');

            return $current->fresh();
        } catch (Throwable $e) {
            // Error de red/5xx/409/respuesta no parseable al consultar:
            // sigue sin saberse el estado real. Nunca se toca ningún
            // dato PAC ya persistido.
            $this->recordReconciliationFailure($current, $e, 'billing.invoice.pac_reconciliation_failed');

            return $current->fresh();
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($result === null) {
            // No encontrada: no se asume automáticamente que nunca
            // existió — se mantiene reconciliation_required=true para
            // reintentar la reconciliación más adelante.
            Log::info('billing.invoice.pac_reconciliation_not_found', [
                'invoice_id' => $current->id,
                'company_id' => $current->company_id,
                'pac_provider' => $current->pac_provider,
                'pac_issue_status' => $current->pac_issue_status,
                'elapsed_ms' => $elapsedMs,
            ]);

            return $current;
        }

        $providerSlug = $this->pacProvider->name();

        $updated = DB::transaction(function () use ($current, $result, $providerSlug): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($current->getKey())
                ->where('company_id', $current->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Nunca sobrescribe una Invoice ya reconciliada/emitida por
            // otra ejecución concurrente (ej. dos llamadas a reconcile()
            // en paralelo, o una emisión que terminó de resolverse justo
            // antes de este lock).
            if ($locked->cfdi_uuid !== null) {
                return $locked;
            }

            // "succeeded" únicamente si la respuesta confirma un CFDI
            // real (status=valid + uuid) — un "pending" encontrado aquí
            // sigue sin ser una emisión exitosa, aunque ya no sea 404.
            $isValid = $result->status === 'valid' && $result->uuid !== null;
            $hadCfdiBefore = $locked->cfdi_uuid !== null;

            $attributes = [
                'pac_provider' => $providerSlug,
                'pac_external_id' => $result->externalId,
                'cfdi_uuid' => $result->uuid,
                'pac_status' => $result->status,
                'stamped_at' => $result->stampedAt,
                'last_pac_sync_at' => now(),
                'pac_response' => $result->rawResponse,
                'pac_last_error' => null,
                'pac_issue_status' => $isValid ? 'succeeded' : 'pending',
                'pac_reconciliation_required' => ! $isValid,
            ];

            // El draft (si existía) se transforma en la factura
            // timbrada — nunca se borra su rastro histórico
            // (pac_draft_external_id/pac_draft_idempotency_key), solo se
            // refleja su status remoto más reciente.
            if ($locked->pac_draft_external_id === $result->externalId) {
                $attributes['pac_draft_status'] = $result->status;
            }

            $locked->forceFill($attributes)->save();

            if ($isValid && ! $hadCfdiBefore) {
                DB::afterCommit(function () use ($locked, $result): void {
                    event(new InvoiceIssued($locked, $result));
                });
            }

            return $locked;
        });

        Log::info('billing.invoice.pac_reconciled', [
            'invoice_id' => $updated->id,
            'company_id' => $updated->company_id,
            'pac_provider' => $updated->pac_provider,
            'pac_external_id' => $updated->pac_external_id,
            'elapsed_ms' => $elapsedMs,
        ]);

        return $updated;
    }

    private function recordReconciliationFailure(Invoice $invoice, Throwable $e, string $logEvent): void
    {
        DB::transaction(function () use ($invoice, $e): void {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            // No pisa el pac_last_error de una Invoice que otra ejecución
            // ya resolvió mientras tanto.
            if ($locked->cfdi_uuid !== null) {
                return;
            }

            $locked->forceFill([
                'pac_last_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        });

        Log::info($logEvent, [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'pac_provider' => $invoice->pac_provider,
        ]);
    }

    private function requireCurrentTenantInvoice(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();

        $fresh = $tenantId !== null
            ? Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->first()
            : null;

        if ($fresh === null) {
            throw (new ModelNotFoundException())->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }
}
