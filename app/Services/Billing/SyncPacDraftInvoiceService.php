<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Enums\InvoicePacEventType;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sincroniza el estado de un borrador remoto ya conocido
 * (`pac_draft_external_id`) contra Facturapi (Fase 6.2.4). NUNCA
 * timbra: si `is_ready_to_stamp` llega en `true`, únicamente se refleja
 * localmente — no dispara ninguna llamada al endpoint de timbrado
 * (que ni siquiera existe en este contrato todavía).
 *
 * Solo conoce `PacProvider` — nunca `FacturapiProvider`, URLs, el
 * facade `Http`, el Bearer token, ni la forma JSON específica de un
 * proveedor concreto (mismo aislamiento que
 * ReconcileInvoiceWithPacService, Fase 6.2.1).
 */
class SyncPacDraftInvoiceService
{
    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function sync(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        if ($current->pac_draft_external_id === null) {
            throw new RuntimeException(sprintf(
                'La factura [%d] no tiene un borrador remoto registrado (pac_draft_external_id vacío); no hay nada que sincronizar. Nunca se busca ni se crea uno en silencio.',
                $current->id,
            ));
        }

        $startedAt = microtime(true);

        $result = $this->pacProvider->retrieveDraftInvoice($current->pac_draft_external_id);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $updated = DB::transaction(function () use ($current, $result): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($current->getKey())
                ->where('company_id', $current->company_id)
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

        Log::info('billing.invoice.pac_draft_synced', [
            'invoice_id' => $updated->id,
            'company_id' => $updated->company_id,
            'provider' => $updated->pac_provider,
            'pac_draft_external_id' => $updated->pac_draft_external_id,
            'pac_draft_status' => $updated->pac_draft_status,
            'is_ready_to_stamp' => $updated->pac_draft_ready_to_stamp,
            'elapsed_ms' => $elapsedMs,
        ]);

        $this->audit->appendSafely($updated, InvoicePacEventType::DraftSynced, [
            'pac_draft_external_id_masked' => $this->audit->maskIdentifier($updated->pac_draft_external_id),
            'pac_draft_status' => $updated->pac_draft_status,
            'is_ready_to_stamp' => $updated->pac_draft_ready_to_stamp,
            'remote_total' => $result->total,
            'elapsed_ms' => $elapsedMs,
        ]);

        return $updated;
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
