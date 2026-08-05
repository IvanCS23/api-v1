<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceResult;
use App\Events\Billing\InvoiceIssued;
use App\Exceptions\Billing\PacAuthenticationException;
use App\Exceptions\Billing\PacException;
use App\Exceptions\Billing\PacRateLimitException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\Billing\PacValidationException;
use App\Exceptions\InvoiceAlreadyIssuedException;
use App\Exceptions\InvoiceDraftNotReadyToStampException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Timbra un BORRADOR ya existente en Facturapi TEST (Fase 6.2.5) — el
 * recurso remoto identificado por `pac_draft_external_id` SE TRANSFORMA
 * en la factura timbrada; nunca crea un CFDI nuevo mediante
 * `PacProvider::createInvoice()`.
 *
 * Reutiliza el tracking de emisión de Fase 6.2.1 (`pac_issue_status`,
 * `pac_issue_started_at`, `pac_issue_attempts`,
 * `pac_reconciliation_required`) — deliberadamente NO se crean columnas
 * `pac_stamp_*`: representan exactamente el mismo concepto (ciclo de
 * vida del intento de "hacer que esta Invoice tenga un CFDI real"), sea
 * el origen `createInvoice()` directo o `stampDraftInvoice()` sobre un
 * borrador. `pac_draft_*` nunca se borra tras timbrar — permanece como
 * rastro histórico de que esta emisión pasó por un borrador.
 *
 * Flujo:
 *   1. valida tenant, existencia de draft, que no haya ya una emisión
 *      final (cfdi_uuid/pac_external_id), que no haya otra operación
 *      activa (pac_issue_status=pending) — TODO esto sin HTTP;
 *   2. sincroniza el draft (SyncPacDraftInvoiceService — nunca confía en
 *      el valor local viejo de is_ready_to_stamp);
 *   3. según el status remoto recién sincronizado:
 *      - "valid"   -> ya se timbró (por esta integración o por fuera) ->
 *                     reconcilia, NUNCA vuelve a llamar /stamp;
 *      - "pending" -> hay una operación remota en curso -> bloquea
 *                     (InvoiceIssuanceInProgressException), nunca
 *                     duplica la llamada;
 *      - "draft" + is_ready_to_stamp=false -> bloquea
 *                     (InvoiceDraftNotReadyToStampException);
 *      - "draft" + is_ready_to_stamp=true  -> continúa;
 *   4. reserva corta (pending, attempts++), cierra ANTES del HTTP;
 *   5. `PacProvider::stampDraftInvoice()` — fuera de cualquier
 *      transacción;
 *   6. según la respuesta:
 *      - "valid" + uuid  -> succeeded, dispatch InvoiceIssued tras
 *        commit, únicamente si `cfdi_uuid` pasó de null a un valor real
 *        en ESTA operación (nunca dos veces);
 *      - "pending"       -> se conserva pending/reconciliation_required,
 *        nunca se dispara el evento;
 *      - fallo definitivo (400/401/403/429) -> failed, reintentable;
 *      - fallo ambiguo (409/5xx/timeout/respuesta inesperada) ->
 *        reconciliation_required, nunca se reintenta /stamp a ciegas.
 */
class StampPacDraftInvoiceService
{
    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly SyncPacDraftInvoiceService $syncDraft,
        private readonly ReconcileInvoiceWithPacService $reconcile,
    ) {}

    public function stamp(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        $this->assertHasDraft($current);
        $this->assertNotAlreadyIssued($current);

        if ($current->pac_issue_status === 'pending') {
            throw new InvoiceIssuanceInProgressException($current);
        }

        // Nunca se confía en el valor local antiguo de
        // pac_draft_ready_to_stamp — se sincroniza siempre antes de
        // decidir.
        $synced = $this->syncDraft->sync($current);

        $this->assertNotAlreadyIssued($synced);

        return match ($synced->pac_draft_status) {
            'valid' => $this->reconcile->reconcile($synced),
            'pending' => throw new InvoiceIssuanceInProgressException($synced),
            'draft' => $this->proceedToStamp($synced),
            default => throw new RuntimeException(sprintf(
                'La factura [%d] tiene un estado remoto de borrador inesperado tras sincronizar: "%s".',
                $synced->id,
                $synced->pac_draft_status ?? 'null',
            )),
        };
    }

    private function proceedToStamp(Invoice $synced): Invoice
    {
        if ($synced->pac_draft_ready_to_stamp !== true) {
            throw new InvoiceDraftNotReadyToStampException($synced);
        }

        $reserved = $this->reserve($synced);

        $startedAt = microtime(true);

        try {
            $result = $this->pacProvider->stampDraftInvoice($reserved->pac_draft_external_id);
        } catch (Throwable $e) {
            $elapsedMs = $this->elapsedMs($startedAt);
            $failed = $this->recordStampFailure($reserved, $e);
            $this->logAttempt($failed, $elapsedMs, $e);

            throw $e;
        }

        $elapsedMs = $this->elapsedMs($startedAt);

        $updated = $this->persistStampResult($reserved, $result);

        $this->logAttempt($updated, $elapsedMs, null);

        return $updated;
    }

    /**
     * Transacción corta: marca `pac_issue_status=pending`,
     * `pac_issue_started_at`, incrementa `pac_issue_attempts` — se
     * cierra (commit) antes de tocar la red. Mismo patrón que
     * IssueInvoiceService::reserve() (Fase 6.2.1).
     */
    private function reserve(Invoice $invoice): Invoice
    {
        $providerSlug = $this->pacProvider->name();

        return DB::transaction(function () use ($invoice, $providerSlug): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNotAlreadyIssued($locked);

            if ($locked->pac_issue_status === 'pending') {
                throw new InvoiceIssuanceInProgressException($locked);
            }

            $locked->forceFill([
                'pac_provider' => $providerSlug,
                'pac_issue_status' => 'pending',
                'pac_issue_started_at' => now(),
                'pac_issue_attempts' => $locked->pac_issue_attempts + 1,
            ])->save();

            return $locked;
        });
    }

    /**
     * Persiste el resultado de un `/stamp` exitoso. `pac_external_id`
     * normalmente coincide con `pac_draft_external_id` (el draft se
     * transforma en la factura timbrada, nunca se crea un segundo
     * recurso) — si el PAC devolviera un `id` distinto, sería una
     * anomalía real: se detiene con PacUnexpectedResponseException en
     * vez de persistir en silencio un posible recurso equivocado.
     *
     * "succeeded" únicamente si `status === "valid"` y trae `uuid` —
     * "pending" se conserva como tal (nunca se inventa un éxito), y
     * `InvoiceIssued` solo se despacha si `cfdi_uuid` pasó de null a un
     * valor real en ESTA transacción (exactamente una vez, Fase 6.2.5
     * §15).
     */
    private function persistStampResult(Invoice $reserved, PacInvoiceResult $result): Invoice
    {
        if ($result->externalId !== $reserved->pac_draft_external_id) {
            throw new PacUnexpectedResponseException(sprintf(
                'stampDraftInvoice devolvió un id remoto (%s) distinto del borrador que se pidió timbrar (%s); no se persiste.',
                $result->externalId,
                $reserved->pac_draft_external_id,
            ), null);
        }

        $providerSlug = $this->pacProvider->name();

        return DB::transaction(function () use ($reserved, $result, $providerSlug): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($reserved->getKey())
                ->where('company_id', $reserved->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $hadCfdiBefore = $locked->cfdi_uuid !== null;

            if ($hadCfdiBefore) {
                // Ya se resolvió por otra vía (ej. una reconciliación
                // concurrente) — nunca se sobrescribe ni se despacha el
                // evento de nuevo.
                return $locked;
            }

            $isValid = $result->status === 'valid' && $result->uuid !== null;

            $locked->forceFill([
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
                // El draft se transforma en la factura timbrada — nunca
                // se borra pac_draft_external_id/pac_draft_idempotency_key
                // (trazabilidad histórica), solo se refleja el status
                // remoto más reciente.
                'pac_draft_status' => $result->status,
            ])->save();

            if ($isValid) {
                DB::afterCommit(function () use ($locked, $result): void {
                    event(new InvoiceIssued($locked, $result));
                });
            }

            return $locked;
        });
    }

    /**
     * Persiste el desenlace de un intento de /stamp fallido en su
     * propia transacción corta. Nunca pisa un resultado ya persistido
     * por otra ejecución.
     */
    private function recordStampFailure(Invoice $invoice, Throwable $e): Invoice
    {
        $status = $this->isDefinitiveFailure($e) ? 'failed' : 'reconciliation_required';

        return DB::transaction(function () use ($invoice, $status, $e): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->cfdi_uuid !== null) {
                return $locked;
            }

            $locked->forceFill([
                'pac_issue_status' => $status,
                'pac_last_error' => $this->sanitizeErrorMessage($e),
                'pac_reconciliation_required' => $status === 'reconciliation_required',
            ])->save();

            return $locked;
        });
    }

    /**
     * Definitivo (el PAC respondió con certeza que el timbrado NO
     * ocurrió: payload rechazado, autenticación, rate limit — la
     * petición nunca llegó a procesarse) vs. AMBIGUO (409 — estado
     * potencialmente incompatible o en transición, nunca se sabe con
     * certeza sin consultar—, 5xx, timeout, respuesta inesperada): en
     * cualquier caso ambiguo NUNCA se reintenta /stamp a ciegas, se
     * marca reconciliation_required para que
     * ReconcileInvoiceWithPacService resuelva el estado real primero.
     */
    private function isDefinitiveFailure(Throwable $e): bool
    {
        return $e instanceof PacValidationException
            || $e instanceof PacAuthenticationException
            || $e instanceof PacRateLimitException;
    }

    private function sanitizeErrorMessage(Throwable $e): string
    {
        $code = $e instanceof PacException ? ($e->pacCode ?? (string) $e->httpStatus) : null;
        $prefix = $code !== null && $code !== '' ? "[{$code}] " : '';

        return mb_substr($prefix.$e->getMessage(), 0, 500);
    }

    private function logAttempt(Invoice $invoice, int $elapsedMs, ?Throwable $error): void
    {
        Log::info('billing.invoice.pac_stamp_attempt', [
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'provider' => $invoice->pac_provider,
            'pac_issue_status' => $invoice->pac_issue_status,
            'pac_external_id' => $invoice->pac_external_id,
            'attempt' => $invoice->pac_issue_attempts,
            'elapsed_ms' => $elapsedMs,
            'pac_error_code' => $error instanceof PacException ? $error->pacCode : null,
        ]);
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function assertHasDraft(Invoice $invoice): void
    {
        if ($invoice->pac_draft_external_id === null) {
            throw new RuntimeException(sprintf(
                'La factura [%d] no tiene un borrador remoto registrado; no se puede timbrar. Usa CreatePacDraftInvoiceService primero.',
                $invoice->id,
            ));
        }
    }

    private function assertNotAlreadyIssued(Invoice $invoice): void
    {
        if ($invoice->cfdi_uuid !== null || $invoice->pac_external_id !== null) {
            throw new InvoiceAlreadyIssuedException($invoice);
        }
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
