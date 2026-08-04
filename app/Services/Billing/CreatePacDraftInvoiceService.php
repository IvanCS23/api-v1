<?php

namespace App\Services\Billing;

use App\Contracts\Billing\PacProvider;
use App\Data\Billing\PacInvoiceRequest;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacIdentifiers;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crea (o sincroniza, si ya existe) un BORRADOR remoto en Facturapi TEST
 * — Fase 6.2.4. Flujo: Invoice → readiness local → reservar la
 * operación de draft → PacProvider::createDraftInvoice() → persistir →
 * devolver la Invoice actualizada.
 *
 * NUNCA llama a IssueInvoiceService ni construye nada relacionado con
 * timbrado real: no modifica `cfdi_uuid`, `stamped_at`, ni fija
 * `pac_issue_status=succeeded` — un borrador no es un CFDI. El estado
 * remoto del borrador vive exclusivamente en las columnas `pac_draft_*`,
 * separadas por completo del tracking de emisión final (Fase 6.1/6.2.1).
 * `Invoice::status` (dominio interno, InvoiceStatus) no se toca — sigue
 * siendo `Issued` si ya lo era; esta fase no agrega estados nuevos.
 *
 * Idempotente: si la Invoice ya tiene `pac_draft_external_id`, NUNCA
 * crea un segundo borrador — delega en SyncPacDraftInvoiceService para
 * recuperar/actualizar el existente. La llave de idempotencia del draft
 * (`PacIdentifiers::draftIdempotencyKey()`) es determinista y DISTINTA
 * de la de emisión final — un reintento de "crear el mismo draft"
 * siempre recalcula/reutiliza la misma llave.
 */
class CreatePacDraftInvoiceService
{
    public function __construct(
        private readonly PacProvider $pacProvider,
        private readonly InvoicePacReadinessService $readiness,
        private readonly SyncPacDraftInvoiceService $sync,
    ) {}

    public function createOrSync(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        if ($current->pac_draft_external_id !== null) {
            // Idempotencia: ya existe un borrador remoto — nunca se crea
            // otro, se sincroniza el que ya hay.
            return $this->sync->sync($current);
        }

        $this->readiness->assertReady($current);

        $providerSlug = $this->pacProvider->name();
        $idempotencyKey = $current->pac_draft_idempotency_key
            ?? PacIdentifiers::draftIdempotencyKey($current->company_id, $current->id);

        $reserved = $this->reserveDraftKey($current, $idempotencyKey, $providerSlug);

        if ($reserved->pac_draft_external_id !== null) {
            // Otra ejecución concurrente ganó la carrera y ya completó el
            // borrador mientras esperábamos el lock de la reserva.
            return $this->sync->sync($reserved);
        }

        $externalId = PacIdentifiers::draftExternalId($reserved->company_id, $reserved->id);
        $pacRequest = new PacInvoiceRequest(
            invoice: $reserved,
            idempotencyKey: $reserved->pac_draft_idempotency_key,
            externalId: $externalId,
        );

        $startedAt = microtime(true);

        $result = $this->pacProvider->createDraftInvoice($pacRequest);

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $updated = DB::transaction(function () use ($reserved, $result, $providerSlug): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($reserved->getKey())
                ->where('company_id', $reserved->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            // Defensa en profundidad: si otra ejecución ya persistió un
            // borrador entre nuestra reserva y este commit, no lo
            // sobrescribimos con nuestra propia respuesta (que sería un
            // segundo recurso real en Facturapi, ya innecesario).
            if ($locked->pac_draft_external_id !== null) {
                return $locked;
            }

            $locked->forceFill([
                'pac_provider' => $providerSlug,
                'pac_draft_external_id' => $result->externalId,
                'pac_draft_status' => $result->status,
                'pac_draft_ready_to_stamp' => $result->isReadyToStamp,
                'pac_draft_created_at' => $result->createdAt,
                'pac_draft_last_sync_at' => now(),
                'pac_draft_response' => $result->rawResponse,
            ])->save();

            return $locked;
        });

        Log::info('billing.invoice.pac_draft_created', [
            'invoice_id' => $updated->id,
            'company_id' => $updated->company_id,
            'provider' => $updated->pac_provider,
            'pac_draft_external_id' => $updated->pac_draft_external_id,
            'pac_draft_status' => $updated->pac_draft_status,
            'is_ready_to_stamp' => $updated->pac_draft_ready_to_stamp,
            'elapsed_ms' => $elapsedMs,
        ]);

        return $updated;
    }

    /**
     * Transacción corta: fija `pac_provider`/`pac_draft_idempotency_key`
     * ANTES de tocar la red — se cierra (commit) antes del HTTP, mismo
     * patrón que IssueInvoiceService::reserve() (Fase 6.2.1). Si la
     * Invoice ya tiene una llave de un intento anterior, la reutiliza
     * tal cual (nunca genera una distinta para el mismo borrador).
     */
    private function reserveDraftKey(Invoice $invoice, string $idempotencyKey, string $providerSlug): Invoice
    {
        return DB::transaction(function () use ($invoice, $idempotencyKey, $providerSlug): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $invoice->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->pac_draft_external_id !== null || $locked->pac_draft_idempotency_key !== null) {
                return $locked;
            }

            $locked->forceFill([
                'pac_provider' => $providerSlug,
                'pac_draft_idempotency_key' => $idempotencyKey,
            ])->save();

            return $locked;
        });
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
