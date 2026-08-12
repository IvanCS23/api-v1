<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Enums\InvoicePacEventType;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Actualiza (PUT) el MISMO borrador ya existente en Facturapi TEST con
 * el snapshot fiscal actual de Invoice/InvoiceItems (Fase 6.2.7) —
 * nunca crea un draft nuevo, nunca timbra. Caso motivador: un draft
 * remoto creado ANTES de corregir `FacturapiProvider::buildBasePayload()`
 * (sku/tax_included) conserva el payload viejo hasta que se actualiza
 * explícitamente; `SyncPacDraftInvoiceService`/`CreatePacDraftInvoiceService`
 * solo LEEN el estado remoto, nunca reenvían el payload.
 *
 * Siempre sincroniza primero (`SyncPacDraftInvoiceService`) — nunca
 * confía en el `pac_draft_status` local desactualizado. Según el status
 * remoto recién sincronizado (mismo árbol de decisión que
 * `StampPacDraftInvoiceService`, Fase 6.2.5, reutilizado aquí por
 * consistencia — "la política existente de sincronización/
 * reconciliación" que pide esta fase):
 *   - "draft"   -> procede con `PacProvider::updateDraftInvoice()`;
 *   - "valid"   -> ya se timbró (por esta integración o fuera de ella) ->
 *                  delega en `ReconcileInvoiceWithPacService`, nunca
 *                  intenta actualizar un recurso que ya no es editable
 *                  (Facturapi solo permite editar `status: "draft"`);
 *   - "pending" -> operación remota en curso -> bloquea
 *                  (`InvoiceIssuanceInProgressException`), nunca
 *                  actualiza mientras tanto;
 *   - cualquier otro status (ej. "canceled") -> estado inesperado tras
 *     sincronizar, se detiene con `RuntimeException` explícito en vez de
 *     intentar un PUT que Facturapi rechazaría de todos modos.
 *
 * Sin reserva `pac_issue_status=pending` (a diferencia de
 * `StampPacDraftInvoiceService`): `PUT` sobre un recurso existente es
 * naturalmente idempotente (mismo `$externalId`, mismo payload
 * determinista) y esta operación nunca toca `pac_issue_*` — no hay un
 * efecto de "doble timbrado" que prevenir con una reserva.
 *
 * Consistencia de totales (Fase 6.2.7 §6): nunca recalcula el total
 * local para forzar coincidencia con el remoto — solo lo COMPARA
 * (`PacInvoiceDraftResult::$total` vs `Invoice::total`) y deja
 * constancia explícita en el log (`billing.invoice.pac_draft_total_mismatch`
 * si difieren) para revisión manual; el comando
 * `billing:facturapi-test-draft-update` también lo muestra.
 */
class UpdatePacDraftInvoiceService
{
    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly SyncPacDraftInvoiceService $syncDraft,
        private readonly ReconcileInvoiceWithPacService $reconcile,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function update(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        $this->assertHasDraft($current);

        $synced = $this->syncDraft->sync($current);

        return match ($synced->pac_draft_status) {
            'draft' => $this->performUpdate($synced),
            'valid' => $this->reconcile->reconcile($synced),
            'pending' => throw new InvoiceIssuanceInProgressException($synced),
            default => throw new RuntimeException(sprintf(
                'La factura [%d] tiene un estado remoto de borrador inesperado tras sincronizar: "%s"; no se intenta actualizar.',
                $synced->id,
                $synced->pac_draft_status ?? 'null',
            )),
        };
    }

    private function performUpdate(Invoice $synced): Invoice
    {
        $idempotencyKey = $synced->pac_draft_idempotency_key
            ?? PacIdentifiers::draftIdempotencyKey($synced->company_id, $synced->id);
        $ourExternalReference = PacIdentifiers::draftExternalId($synced->company_id, $synced->id);

        $pacRequest = new PacInvoiceRequest(
            invoice: $synced,
            idempotencyKey: $idempotencyKey,
            externalId: $ourExternalReference,
        );

        $startedAt = microtime(true);
        $result = $this->pacProvider->updateDraftInvoice($synced->pac_draft_external_id, $pacRequest);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $updated = DB::transaction(function () use ($synced, $result): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($synced->getKey())
                ->where('company_id', $synced->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->forceFill([
                'pac_draft_status' => $result->status,
                'pac_draft_ready_to_stamp' => $result->isReadyToStamp,
                'pac_draft_last_sync_at' => now(),
                'pac_draft_response' => $result->rawResponse,
            ])->save();

            return $locked;
        });

        $localTotal = (float) $updated->total;
        $totalsMatch = $result->total === null || abs($result->total - $localTotal) < 0.005;

        Log::info('billing.invoice.pac_draft_updated', [
            'invoice_id' => $updated->id,
            'company_id' => $updated->company_id,
            'provider' => $updated->pac_provider,
            'pac_draft_external_id' => $updated->pac_draft_external_id,
            'pac_draft_status' => $updated->pac_draft_status,
            'is_ready_to_stamp' => $updated->pac_draft_ready_to_stamp,
            'local_total' => $localTotal,
            'remote_total' => $result->total,
            'totals_match' => $totalsMatch,
            'elapsed_ms' => $elapsedMs,
        ]);

        if (! $totalsMatch) {
            // Nunca se oculta ni se recalcula localmente para forzar
            // coincidencia — solo se deja constancia explícita para
            // revisión manual.
            Log::warning('billing.invoice.pac_draft_total_mismatch', [
                'invoice_id' => $updated->id,
                'company_id' => $updated->company_id,
                'local_total' => $localTotal,
                'remote_total' => $result->total,
            ]);
        }

        $this->audit->appendSafely($updated, InvoicePacEventType::DraftUpdated, [
            'pac_draft_external_id_masked' => $this->audit->maskIdentifier($updated->pac_draft_external_id),
            'pac_draft_status' => $updated->pac_draft_status,
            'is_ready_to_stamp' => $updated->pac_draft_ready_to_stamp,
            'local_total' => $localTotal,
            'remote_total' => $result->total,
            'totals_match' => $totalsMatch,
            'elapsed_ms' => $elapsedMs,
        ]);

        return $updated;
    }

    private function assertHasDraft(Invoice $invoice): void
    {
        if ($invoice->pac_draft_external_id === null) {
            throw new RuntimeException(sprintf(
                'La factura [%d] no tiene un borrador remoto registrado; no hay nada que actualizar. Usa CreatePacDraftInvoiceService primero.',
                $invoice->id,
            ));
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
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $fresh;
    }
}
