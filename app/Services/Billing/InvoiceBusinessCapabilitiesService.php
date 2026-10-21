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
    /**
     * Estados remotos con un CFDI timbrado cuyo XML/PDF Facturapi entrega.
     * Fase 6.21: `canceled` verificado en Facturapi TEST (GET /xml y /pdf
     * de un CFDI cancelado responden 200 y el UUID del XML coincide).
     */
    public const ARTIFACT_PAC_STATUSES = ['valid', 'canceled'];

    /** @return array{can_issue: bool, can_cancel: bool, can_reconcile: bool, can_fetch_artifacts: bool, cancellation_mode: ?string} */
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
        $canFetchArtifacts = $this->artifactsFetchable($invoice)
            && $user?->can('managePacArtifacts', $invoice) === true;

        return [
            'can_issue' => $canIssue,
            'can_cancel' => $canCancel,
            'can_reconcile' => $canReconcile,
            'can_fetch_artifacts' => $canFetchArtifacts,
            'cancellation_mode' => $canCancel ? $mode : null,
        ];
    }

    /**
     * Fase 6.21: única regla para obtener XML/PDF. La comparten
     * DownloadInvoiceArtifactsService, la capability can_fetch_artifacts y
     * billing:health. Un CFDI timbrado (vigente o cancelado) sin
     * artifacts almacenados y sin otra descarga en curso.
     */
    public function artifactsFetchable(Invoice $invoice): bool
    {
        return $this->hasCompleteFiscalIdentity($invoice)
            && in_array($invoice->pac_status, self::ARTIFACT_PAC_STATUSES, true)
            && $invoice->cfdi_artifacts_status !== 'stored'
            && ! $this->artifactsDownloadInProgress($invoice);
    }

    /**
     * Fase 6.21: una descarga reservada (`pending`) más reciente que
     * `billing.artifacts.pending_minutes`. Una más vieja, o sin fecha de
     * inicio (anterior a la columna), se considera abandonada y puede
     * retomarse.
     */
    /**
     * Fase 6.22: misma regla que artifactsDownloadInProgress() para el acuse
     * de cancelación (`billing.cancellation_receipt.pending_minutes`).
     */
    public function cancellationReceiptDownloadInProgress(Invoice $invoice): bool
    {
        return $invoice->cancellation_receipt_status === 'pending'
            && $invoice->cancellation_receipt_started_at !== null
            && $invoice->cancellation_receipt_started_at->greaterThan(
                now()->subMinutes((int) config('billing.cancellation_receipt.pending_minutes')),
            );
    }

    public function artifactsDownloadInProgress(Invoice $invoice): bool
    {
        return $invoice->cfdi_artifacts_status === 'pending'
            && $invoice->cfdi_artifacts_started_at !== null
            && $invoice->cfdi_artifacts_started_at->greaterThan(
                now()->subMinutes((int) config('billing.artifacts.pending_minutes')),
            );
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
            || $this->hasOpenCancelRequest($invoice)
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
            || $this->hasOpenCancelRequest($invoice)
            || $invoice->pac_status === 'pending'
            // Fase 6.21: sólo un borrador valid SIN vincular. Al timbrar, el
            // borrador se transforma en el CFDI y conserva
            // pac_draft_status=valid; con pac_external_id ya no hay nada que
            // reconciliar (misma regla que remote_draft_valid_unlinked).
            || ($invoice->pac_draft_status === 'valid' && blank($invoice->pac_external_id))
            || $this->hasUnlinkedDraftReservation($invoice);
    }

    /**
     * Fase 6.20: la llave del borrador quedó reservada pero nunca se
     * persistió el id remoto (la creación falló o quedó sin respuesta).
     * Repetir el POST con la misma llave responde siempre
     * `idempotency_key_in_use`, así que sólo la reconciliación puede
     * vincular el borrador, buscándolo por `draftExternalId`.
     */
    public function hasUnlinkedDraftReservation(Invoice $invoice): bool
    {
        return filled($invoice->pac_draft_idempotency_key)
            && blank($invoice->pac_draft_external_id)
            && blank($invoice->pac_external_id)
            && blank($invoice->pac_idempotency_key)
            && $invoice->pac_issue_status === null;
    }

    /**
     * Fase 6.19: una solicitud de cancelación reservada (`in_flight`) o
     * ambigua bloquea un nuevo DELETE hasta que la reconciliación la
     * resuelva.
     */
    public function hasOpenCancelRequest(Invoice $invoice): bool
    {
        return in_array($invoice->pac_cancel_request_status, [
            CancelInvoiceWithPacService::REQUEST_IN_FLIGHT,
            CancelInvoiceWithPacService::REQUEST_RECONCILIATION_REQUIRED,
        ], true);
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
