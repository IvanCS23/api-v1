<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceNotReadyForPacException;
use App\Exceptions\Billing\PacReconciliationRequiredException;
use App\Exceptions\Billing\PacResourceCanceledException;
use App\Exceptions\Billing\PacUnexpectedResponseException;
use App\Exceptions\InvoiceCannotBeIssuedException;
use App\Exceptions\InvoiceDraftNotReadyToStampException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Orquesta la intención de negocio "Emitir CFDI" sobre servicios PAC
 * existentes. No conoce proveedores, payloads, HTTP, locks ni retries.
 */
class IssueInvoiceToPacService
{
    public function __construct(
        private readonly InvoicePacReadinessService $readiness,
        private readonly CreatePacDraftInvoiceService $createDraft,
        private readonly SyncPacDraftInvoiceService $syncDraft,
        private readonly UpdatePacDraftInvoiceService $updateDraft,
        private readonly StampPacDraftInvoiceService $stampDraft,
        private readonly ReconcileInvoiceWithPacService $reconcile,
    ) {}

    public function issue(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        if ($this->isIssued($current)) {
            return $current;
        }

        $this->assertEligible($current);
        $this->assertNoActiveOrAmbiguousIssuance($current);
        $this->assertReady($current);

        try {
            $draft = $current->pac_draft_external_id === null
                ? $this->createDraft->createOrSync($current)
                : $this->syncDraft->sync($current);

            return $this->advance($draft);
        } catch (Throwable $error) {
            if ($error instanceof PacReconciliationRequiredException) {
                throw $error;
            }

            $fresh = $this->requireCurrentTenantInvoice($current);

            if ($this->requiresReconciliation($fresh)) {
                throw new PacReconciliationRequiredException($error);
            }

            throw $error;
        }
    }

    private function advance(Invoice $invoice): Invoice
    {
        if ($this->isIssued($invoice)) {
            return $invoice;
        }

        return match ($invoice->pac_draft_status) {
            'valid' => $this->reconcileValid($invoice),
            'pending' => throw new InvoiceIssuanceInProgressException($invoice),
            'canceled' => throw new PacResourceCanceledException,
            'draft' => $this->prepareAndStamp($invoice),
            default => throw new PacUnexpectedResponseException(
                'El PAC devolvió un estado de borrador no reconocido durante la emisión.',
            ),
        };
    }

    private function prepareAndStamp(Invoice $draft): Invoice
    {
        $prepared = $draft;

        if ($draft->pac_draft_ready_to_stamp !== true) {
            $prepared = $this->updateDraft->update($draft);

            if ($this->isIssued($prepared)) {
                return $prepared;
            }

            if ($prepared->pac_draft_status === 'valid') {
                return $this->reconcileValid($prepared);
            }

            if ($prepared->pac_draft_status === 'pending') {
                throw new InvoiceIssuanceInProgressException($prepared);
            }

            if ($prepared->pac_draft_status === 'canceled') {
                throw new PacResourceCanceledException;
            }

            if ($prepared->pac_draft_status !== 'draft'
                || $prepared->pac_draft_ready_to_stamp !== true) {
                throw new InvoiceDraftNotReadyToStampException($prepared);
            }
        }

        $stamped = $this->stampDraft->stamp($prepared);

        if ($this->isIssued($stamped)) {
            return $stamped;
        }

        if ($this->requiresReconciliation($stamped)) {
            throw new PacReconciliationRequiredException;
        }

        if ($stamped->pac_issue_status === 'pending' || $stamped->pac_status === 'pending') {
            throw new InvoiceIssuanceInProgressException($stamped);
        }

        throw new PacUnexpectedResponseException(
            'El PAC no confirmó la emisión fiscal como válida.',
        );
    }

    private function reconcileValid(Invoice $invoice): Invoice
    {
        $reconciled = $this->reconcile->reconcile($invoice, throwOnFailure: true);

        if ($this->isIssued($reconciled)) {
            return $reconciled;
        }

        throw new PacReconciliationRequiredException;
    }

    private function assertEligible(Invoice $invoice): void
    {
        if ($invoice->status !== InvoiceStatus::Issued) {
            throw new InvoiceCannotBeIssuedException($invoice);
        }

        if ($invoice->pac_external_id !== null) {
            if ($invoice->pac_status === 'canceled') {
                throw new PacResourceCanceledException;
            }

            throw new PacReconciliationRequiredException;
        }
    }

    private function assertNoActiveOrAmbiguousIssuance(Invoice $invoice): void
    {
        if ($invoice->pac_issue_status === 'pending') {
            throw new InvoiceIssuanceInProgressException($invoice);
        }

        if ($this->requiresReconciliation($invoice)) {
            throw new PacReconciliationRequiredException;
        }
    }

    private function assertReady(Invoice $invoice): void
    {
        $result = $this->readiness->evaluate($invoice);

        if ($result['ready']) {
            return;
        }

        throw new InvoiceNotReadyForPacException(array_map(
            static fn (array $error): array => [
                'code' => $error['code'],
                'field' => $error['field'],
            ],
            $result['errors'],
        ));
    }

    private function isIssued(Invoice $invoice): bool
    {
        return $invoice->cfdi_uuid !== null
            || $invoice->pac_issue_status === 'succeeded';
    }

    private function requiresReconciliation(Invoice $invoice): bool
    {
        return $invoice->pac_reconciliation_required
            || $invoice->pac_issue_status === 'reconciliation_required';
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
