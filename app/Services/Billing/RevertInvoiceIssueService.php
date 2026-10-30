<?php

namespace App\Services\Billing;

use App\Enums\InvoicePacEventType;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceCannotBeRevertedException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Billing\PacOrganizationAccess;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Fase 6.29 — "Regresar a lista": devuelve a `ready` una factura `issued`
 * cuyo timbrado fue rechazado (o nunca ocurrió), para que pueda
 * corregirse —eliminarla y volver a facturar la venta con los datos
 * corregidos— o reintentarse. Sus datos son un snapshot: sin esto quedaba
 * atascada (no editable, no eliminable y, con borrador remoto, sin
 * cancelación sólo ERP).
 *
 * Si hay borrador remoto se consulta primero: sólo se regresa si sigue
 * siendo un borrador. Uno ya timbrado queda registrado por la sincronización
 * (pac_draft_status=valid) y la factura se resuelve reconciliando. El
 * borrador remoto no se borra: se reutiliza en el siguiente intento o queda
 * sin timbrar si la factura se elimina.
 */
class RevertInvoiceIssueService
{
    public function __construct(
        private readonly InvoiceBusinessCapabilitiesService $capabilities,
        private readonly SyncPacDraftInvoiceService $syncDraft,
        private readonly InvoicePacAuditService $audit,
    ) {}

    public function revert(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);
        $this->assertRevertible($current);

        if ($current->pac_draft_external_id !== null) {
            // Fase 6.27: una factura de otra organización no consulta al PAC.
            PacOrganizationAccess::assertAccessible($current);
            $current = $this->syncDraft->sync($current);
            $this->assertRevertible($current);
        }

        $previousIssueStatus = $current->pac_issue_status;

        $reverted = DB::transaction(function () use ($current): Invoice {
            $locked = Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($current->getKey())
                ->where('company_id', $current->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRevertible($locked);

            $locked->forceFill([
                'status' => InvoiceStatus::Ready,
                'issued_at' => null,
                'pac_issue_status' => null,
            ])->save();

            return $locked;
        });

        $this->audit->appendSafely($reverted, InvoicePacEventType::IssueReverted, [
            'previous_issue_status' => $previousIssueStatus,
            'pac_draft_status' => $reverted->pac_draft_status,
        ]);

        return $reverted;
    }

    private function assertRevertible(Invoice $invoice): void
    {
        if ($this->capabilities->canRevertIssueByState($invoice)) {
            return;
        }

        $reason = match (true) {
            $invoice->status !== InvoiceStatus::Issued => "está en estado \"{$invoice->status->value}\", no \"issued\"",
            ! $this->capabilities->hasNoFiscalIdentity($invoice) || $invoice->pac_status !== null => 'ya tiene identidad fiscal',
            in_array($invoice->pac_draft_status, ['pending', 'valid', 'canceled'], true) => "su borrador remoto está \"{$invoice->pac_draft_status}\"",
            default => 'tiene una operación fiscal en curso o en revisión',
        };

        throw new InvoiceCannotBeRevertedException($invoice, $reason);
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
