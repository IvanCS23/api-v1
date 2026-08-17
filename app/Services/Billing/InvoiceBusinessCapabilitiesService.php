<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;

/**
 * Deriva capacidades empresariales sanitizadas desde ambas máquinas de
 * estados. No modifica la Invoice ni consulta al PAC.
 */
class InvoiceBusinessCapabilitiesService
{
    /** @return array{can_issue: bool, can_cancel: bool, can_reconcile: bool, cancellation_mode: ?string} */
    public function for(Invoice $invoice, ?User $user): array
    {
        $canIssue = $user?->can('issueBusiness', $invoice) === true
            && $this->canIssueByState($invoice);
        $mode = $this->cancellationMode($invoice);
        $canCancel = $mode !== null
            && $user?->can('cancelBusiness', $invoice) === true;
        $canReconcile = $this->needsReconciliationAction($invoice)
            && $this->hasReconciliationContext($invoice)
            && $user?->can('reconcilePac', $invoice) === true;

        return [
            'can_issue' => $canIssue,
            'can_cancel' => $canCancel,
            'can_reconcile' => $canReconcile,
            'cancellation_mode' => $canCancel ? $mode : null,
        ];
    }

    public function isSuccessfulIssue(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued
            && filled($invoice->pac_external_id)
            && filled($invoice->cfdi_uuid)
            && $invoice->pac_status === 'valid'
            && $invoice->pac_issue_status === 'succeeded'
            && ! $this->requiresReview($invoice);
    }

    public function canIssueByState(Invoice $invoice): bool
    {
        if (! in_array($invoice->status, [InvoiceStatus::Ready, InvoiceStatus::Issued], true)
            || $this->requiresReview($invoice)
            || filled($invoice->pac_external_id)
            || filled($invoice->cfdi_uuid)
            || in_array($invoice->pac_issue_status, ['pending', 'succeeded', 'reconciliation_required'], true)
            || in_array($invoice->pac_status, ['pending', 'valid', 'canceled'], true)
            || in_array($invoice->pac_draft_status, ['pending', 'valid', 'canceled'], true)) {
            return false;
        }

        return true;
    }

    public function cancellationMode(Invoice $invoice): ?string
    {
        if ($this->requiresReview($invoice)
            || $invoice->pac_issue_status === 'pending'
            || in_array($invoice->cancellation_status, ['pending', 'verifying'], true)) {
            return null;
        }

        if ($this->hasCompleteFiscalIdentity($invoice)) {
            if ($invoice->pac_status === 'canceled') {
                return $invoice->status === InvoiceStatus::Cancelled ? null : 'erp_convergence';
            }

            if ($invoice->pac_status === 'valid'
                && ! in_array($invoice->cancellation_status, ['accepted'], true)) {
                return 'pac';
            }

            return null;
        }

        if (! $this->hasNoFiscalIdentity($invoice)
            || $invoice->pac_status !== null
            || $invoice->pac_issue_status === 'succeeded'
            || filled($invoice->pac_draft_external_id)
            || filled($invoice->pac_draft_idempotency_key)
            || in_array($invoice->pac_draft_status, ['pending', 'valid', 'canceled'], true)
            || $invoice->status === InvoiceStatus::Cancelled) {
            return null;
        }

        return in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Ready, InvoiceStatus::Issued], true)
            ? 'erp_only'
            : null;
    }

    public function requiresReview(Invoice $invoice): bool
    {
        return (bool) $invoice->pac_reconciliation_required
            || $invoice->pac_issue_status === 'reconciliation_required';
    }

    private function needsReconciliationAction(Invoice $invoice): bool
    {
        $hasPartialIdentity = (filled($invoice->pac_external_id) xor filled($invoice->cfdi_uuid));

        return $this->requiresReview($invoice)
            || $hasPartialIdentity
            || $invoice->pac_status === 'pending'
            || $invoice->pac_draft_status === 'valid';
    }

    public function hasCompleteFiscalIdentity(Invoice $invoice): bool
    {
        return filled($invoice->pac_external_id) && filled($invoice->cfdi_uuid);
    }

    public function hasNoFiscalIdentity(Invoice $invoice): bool
    {
        return blank($invoice->pac_external_id) && blank($invoice->cfdi_uuid);
    }

    private function hasReconciliationContext(Invoice $invoice): bool
    {
        return filled($invoice->pac_external_id)
            || filled($invoice->pac_draft_external_id)
            || filled($invoice->pac_idempotency_key)
            || filled($invoice->pac_draft_idempotency_key);
    }
}
