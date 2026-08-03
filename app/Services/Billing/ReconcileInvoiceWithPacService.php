<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
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
 * marcadas `pac_reconciliation_required=true` por IssueInvoiceService,
 * tras una respuesta ambigua del PAC (timeout, conexión interrumpida,
 * 5xx, respuesta no parseable) donde no se sabe con certeza si Facturapi
 * llegó a crear la factura. Nunca vuelve a emitir (nunca llama
 * `createInvoice()`) ni crea una factura nueva — solo intenta AVERIGUAR
 * el estado real y sincronizarlo.
 *
 * Aislamiento del PAC (sin cambios desde Fase 6.2.1): esta clase solo
 * conoce `PacProvider` (contrato) y `PacInvoiceResult`/
 * `PacAmbiguousInvoiceMatchException` (DTO/excepción del contrato,
 * PAC-agnósticos) — nunca `FacturapiProvider`, URLs, el facade `Http`,
 * el Bearer token, ni la forma JSON específica de un proveedor concreto.
 *
 * Flujo (Fase 6.2.2 — ya no bloqueado):
 *   1. valida tenant;
 *   2. si `pac_external_id` es conocido -> `retrieveInvoice()`;
 *   3. si no -> reconstruye `external_id` determinista con
 *      PacIdentifiers (la MISMA fórmula que usó IssueInvoiceService al
 *      reservar) y usa `findInvoiceByExternalId()`;
 *   4. encontrada -> persiste transaccionalmente, succeeded;
 *   5. no encontrada (null) -> mantiene reconciliation_required=true,
 *      log sanitizado, sin crear nada;
 *   6. ambigua (>1 coincidencia) o error de red/5xx -> mantiene
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

        if ($current->pac_idempotency_key === null) {
            throw new RuntimeException(sprintf(
                'La factura [%d] no tiene contexto de emisión ante el PAC (pac_idempotency_key vacío); no hay nada que reconciliar.',
                $current->id,
            ));
        }

        $startedAt = microtime(true);

        try {
            $result = $current->pac_external_id !== null
                ? $this->pacProvider->retrieveInvoice($current->pac_external_id)
                : $this->pacProvider->findInvoiceByExternalId(
                    PacIdentifiers::externalId($current->company_id, $current->id),
                );
        } catch (PacAmbiguousInvoiceMatchException $e) {
            // Facturapi no garantiza unicidad de external_id — nunca se
            // elige una coincidencia en silencio. Se deja
            // reconciliation_required=true para revisión manual.
            $this->recordReconciliationFailure($current, $e, 'billing.invoice.pac_reconciliation_ambiguous');

            return $current->fresh();
        } catch (Throwable $e) {
            // Error de red/5xx/respuesta no parseable al consultar: sigue
            // sin saberse el estado real. Nunca se toca ningún dato PAC
            // ya persistido.
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
            if ($locked->pac_issue_status === 'succeeded') {
                return $locked;
            }

            $locked->forceFill([
                'pac_provider' => $providerSlug,
                'pac_external_id' => $result->externalId,
                'cfdi_uuid' => $result->uuid,
                'pac_status' => $result->status,
                'stamped_at' => $result->stampedAt,
                'last_pac_sync_at' => now(),
                'pac_response' => $result->rawResponse,
                'pac_last_error' => null,
                'pac_issue_status' => 'succeeded',
                'pac_reconciliation_required' => false,
            ])->save();

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
            if ($locked->pac_issue_status === 'succeeded') {
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
