<?php

namespace App\Services\Billing;

use App\Enums\InvoiceHealthAction as Action;
use App\Enums\InvoiceHealthStatus as Health;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\CancellationReceiptIdentityMismatchException;
use App\Models\Invoice;
use App\Support\Billing\InvoiceHealthFinding as Finding;
use App\Support\Billing\InvoiceHealthReport;
use App\Support\Billing\PacCredentialGuard;
use App\Support\Billing\PacErrorCode;
use App\Support\Billing\PacIdentifiers;
use App\Support\Billing\PacOrganizationAccess;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Clasificador de salud del ciclo ERP/PAC (Fase 6.18).
 *
 * Evaluación pura: no escribe la Invoice, no consulta la BD y no hace
 * HTTP. Reutiliza las reglas de InvoiceBusinessCapabilitiesService
 * (Fase 6.16) para no divergir de lo que la API permite operar. Sólo
 * lee estado local; la comparación contra el PAC queda fuera de alcance.
 */
class InvoiceHealthEvaluator
{
    private const PAC_FINAL_STATUSES = ['valid', 'canceled'];

    private const CANCELLATION_PENDING_STATUSES = ['pending', 'verifying'];

    public function __construct(
        private readonly InvoiceBusinessCapabilitiesService $capabilities,
    ) {}

    /**
     * @param  CarbonImmutable|null  $cancellationRequestedAt  último evento
     *                                                         CancellationRequested; el llamador lo resuelve para mantener
     *                                                         este evaluador libre de consultas.
     */
    public function evaluate(
        Invoice $invoice,
        CarbonImmutable $now,
        ?CarbonImmutable $cancellationRequestedAt = null,
        ?string $companyOrganizationId = null,
    ): InvoiceHealthReport {
        // Fase 6.27: una factura de otra organización de Facturapi no admite
        // ninguna acción PAC, así que sus demás hallazgos (reconciliar,
        // descargar…) no aplican. Archivada, queda sólo como informativa; sin
        // archivar, es inválida y requiere revisión (o archivarla).
        $organization = PacOrganizationAccess::status($invoice, $companyOrganizationId);

        if ($organization === PacOrganizationAccess::ARCHIVED) {
            return InvoiceHealthReport::fromFindings((int) $invoice->id, (int) $invoice->company_id, [
                new Finding('pac_organization_archived', Health::Valid, Action::ManualReview),
            ]);
        }

        if ($organization === PacOrganizationAccess::OTHER) {
            return InvoiceHealthReport::fromFindings((int) $invoice->id, (int) $invoice->company_id, [
                new Finding('pac_organization_mismatch', Health::Invalid, Action::ManualReview),
            ]);
        }

        $findings = [
            ...$this->issuanceFindings($invoice, $now),
            ...$this->cancellationFindings($invoice, $now, $cancellationRequestedAt),
            ...$this->convergenceFindings($invoice),
            ...$this->identityFindings($invoice),
            ...$this->draftFindings($invoice, $now),
            ...$this->artifactFindings($invoice, $now),
        ];

        // El flag de revisión también se activa durante emisiones y
        // cancelaciones pendientes; en ese caso el hallazgo de la operación
        // en curso ya lo explica y no se duplica como recuperable.
        if ($this->capabilities->requiresReview($invoice) && ! $this->reviewExplainedByPendingOperation($invoice)) {
            $findings[] = new Finding('reconciliation_required', Health::Recoverable, Action::Reconcile);
        }

        return InvoiceHealthReport::fromFindings((int) $invoice->id, (int) $invoice->company_id, $findings);
    }

    /** @return list<Finding> */
    private function issuanceFindings(Invoice $invoice, CarbonImmutable $now): array
    {
        if ($this->isIssuePending($invoice)) {
            $age = $this->ageInMinutes($invoice->pac_issue_started_at, $now);

            return [$age !== null && $age < $this->threshold('issue_pending_minutes')
                ? new Finding('issue_pending_recent', Health::Transient, Action::Wait, $age)
                : new Finding('issue_pending_stale', Health::Recoverable, Action::Reconcile, $age)];
        }

        if (! $this->capabilities->hasNoFiscalIdentity($invoice)
            || $invoice->status === InvoiceStatus::Cancelled
            || $this->capabilities->requiresReview($invoice)) {
            return [];
        }

        if ($invoice->pac_issue_status === 'failed') {
            return [new Finding('issue_failed', Health::Recoverable, Action::OperationsIssue)];
        }

        // Fase 6.20: reintentar la emisión repetiría la llave del borrador y
        // Facturapi respondería siempre idempotency_key_in_use; sólo la
        // reconciliación puede vincular el borrador remoto.
        if ($invoice->status === InvoiceStatus::Issued
            && $this->capabilities->hasUnlinkedDraftReservation($invoice)) {
            return [new Finding('draft_reserved_without_remote_id', Health::Recoverable, Action::Reconcile)];
        }

        if ($invoice->status === InvoiceStatus::Issued
            && $invoice->pac_status === null
            && $invoice->pac_draft_status !== 'valid') {
            return [new Finding('erp_issued_without_cfdi', Health::Recoverable, Action::OperationsIssue)];
        }

        return [];
    }

    /** @return list<Finding> */
    private function cancellationFindings(Invoice $invoice, CarbonImmutable $now, ?CarbonImmutable $requestedAt): array
    {
        $findings = [];

        if ($this->isCancellationPending($invoice)) {
            $age = $this->ageInMinutes($requestedAt ?? $invoice->last_pac_sync_at, $now);
            $findings[] = $age !== null && $age < $this->threshold('cancellation_pending_hours') * 60
                ? new Finding('cancellation_pending_recent', Health::Transient, Action::Wait, $age)
                : new Finding('cancellation_pending_stale', Health::Recoverable, Action::Reconcile, $age);
        }

        // Fase 6.19: DELETE reservado cuya respuesta aún no se persiste. Más
        // allá de billing.cancel_in_flight_minutes se asume proceso caído.
        if ($invoice->pac_cancel_request_status === CancelInvoiceWithPacService::REQUEST_IN_FLIGHT) {
            $age = $this->ageInMinutes($invoice->pac_cancel_started_at, $now);
            $findings[] = $age !== null && $age < (int) config('billing.cancel_in_flight_minutes')
                ? new Finding('cancellation_request_in_flight', Health::Transient, Action::Wait, $age)
                : new Finding('cancellation_request_in_flight_stale', Health::Recoverable, Action::Reconcile, $age);
        }

        if ($invoice->cancellation_status === 'accepted' && $invoice->pac_status !== 'canceled') {
            $findings[] = new Finding('cancellation_accepted_not_canceled', Health::Recoverable, Action::Reconcile);
        }

        // El acuse nunca prueba ni escala la cancelación: sólo cuenta
        // pac_status=canceled. Con una solicitud registrada (pending,
        // verifying, accepted, rejected, expired) la clasifican las reglas
        // de cancelación/convergencia; sin ninguna, sólo se informa.
        if ($invoice->cancellation_receipt_status === 'stored'
            && $invoice->pac_status !== 'canceled'
            && in_array($invoice->cancellation_status, [null, 'none'], true)) {
            $findings[] = new Finding('receipt_without_cancellation', Health::Valid, Action::ManualReview);
        }

        if ($invoice->cancellation_receipt_status === 'reconciliation_required') {
            $findings[] = $this->receiptFinding($invoice);
        }

        // Fase 6.22: misma salida por antigüedad que los artifacts (6.21).
        if ($invoice->cancellation_receipt_status === 'pending') {
            $findings[] = $this->capabilities->cancellationReceiptDownloadInProgress($invoice)
                ? new Finding('cancellation_receipt_download_pending', Health::Transient, Action::Wait, $this->ageInMinutes($invoice->cancellation_receipt_started_at, $now))
                : new Finding('cancellation_receipt_download_pending_stale', Health::Recoverable, Action::FetchReceipt, $this->ageInMinutes($invoice->cancellation_receipt_started_at, $now));
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function convergenceFindings(Invoice $invoice): array
    {
        $findings = [];

        if ($invoice->pac_status === 'canceled' && $invoice->status !== InvoiceStatus::Cancelled) {
            $findings[] = new Finding('cfdi_canceled_erp_not_converged', Health::Recoverable, Action::OperationsCancel);
        }

        if ($invoice->status === InvoiceStatus::Cancelled && $invoice->pac_status === 'valid') {
            // Viola el invariante de Fase 6.16: el ERP sólo converge a
            // cancelled cuando pac_status=canceled está confirmado.
            // Incluye rechazos/expiraciones, donde el CFDI sigue vigente.
            $findings[] = $this->isCancellationPending($invoice)
                ? new Finding('erp_cancelled_before_pac_confirmation', Health::Invalid, Action::ManualReview)
                : new Finding('erp_cancelled_cfdi_valid', Health::Recoverable, Action::ManualReview);
        }

        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Ready], true)
            && (! $this->capabilities->hasNoFiscalIdentity($invoice)
                || in_array($invoice->pac_status, self::PAC_FINAL_STATUSES, true))) {
            $findings[] = new Finding('erp_behind_cfdi', Health::Invalid, Action::ManualReview);
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function identityFindings(Invoice $invoice): array
    {
        $findings = [];

        if (filled($invoice->pac_external_id) && blank($invoice->cfdi_uuid) && ! $this->isIssuePending($invoice)) {
            $findings[] = new Finding('identity_incomplete', Health::Recoverable, Action::Reconcile);
        }

        if (blank($invoice->pac_external_id) && filled($invoice->cfdi_uuid)) {
            $findings[] = new Finding('identity_uuid_without_external_id', Health::Invalid, Action::ManualReview);
        }

        if ($this->capabilities->hasNoFiscalIdentity($invoice)
            && in_array($invoice->pac_status, self::PAC_FINAL_STATUSES, true)) {
            $findings[] = new Finding('pac_status_without_identity', Health::Invalid, Action::ManualReview);
        }

        if ($invoice->pac_issue_status === 'succeeded'
            && (! $this->capabilities->hasCompleteFiscalIdentity($invoice)
                || ! in_array($invoice->pac_status, self::PAC_FINAL_STATUSES, true))) {
            $findings[] = new Finding('issue_succeeded_without_valid_cfdi', Health::Invalid, Action::ManualReview);
        }

        if ($this->hasForeignIdempotencyKey($invoice)) {
            $findings[] = new Finding('idempotency_key_mismatch', Health::Invalid, Action::ManualReview);
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function draftFindings(Invoice $invoice, CarbonImmutable $now): array
    {
        if (filled($invoice->pac_external_id)) {
            return [];
        }

        if ($invoice->pac_draft_status === 'valid') {
            return [new Finding('remote_draft_valid_unlinked', Health::Recoverable, Action::Reconcile)];
        }

        if (blank($invoice->pac_draft_external_id)
            || $invoice->pac_draft_status === 'canceled'
            || $invoice->status === InvoiceStatus::Cancelled
            || $this->isIssuePending($invoice)) {
            return [];
        }

        // Un CFDI no puede fecharse más de 72 h atrás; un borrador remoto
        // envejecido puede volverse imposible de timbrar.
        $age = $this->ageInMinutes($invoice->pac_draft_created_at, $now);

        if ($age === null || $age < $this->threshold('draft_date_warning_hours') * 60) {
            return [];
        }

        return [$age < $this->threshold('draft_date_limit_hours') * 60
            ? new Finding('remote_draft_date_window_risk', Health::Transient, Action::OperationsIssue, $age)
            : new Finding('remote_draft_date_window_risk', Health::Recoverable, Action::ManualReview, $age)];
    }

    /** @return list<Finding> */
    private function artifactFindings(Invoice $invoice, CarbonImmutable $now): array
    {
        // Fase 6.21: descarga reservada (manual o por la cola). Más allá de
        // billing.artifacts.pending_minutes se asume proceso caído y puede
        // retomarse.
        if ($invoice->cfdi_artifacts_status === 'pending') {
            $age = $this->ageInMinutes($invoice->cfdi_artifacts_started_at, $now);

            return [$age !== null && $age < (int) config('billing.artifacts.pending_minutes')
                ? new Finding('artifacts_download_pending', Health::Transient, Action::Wait, $age)
                : new Finding('artifacts_download_pending_stale', Health::Recoverable, Action::FetchArtifacts, $age)];
        }

        // Fase 6.21: misma regla que el servicio y can_fetch_artifacts; sólo
        // se sugiere fetch_artifacts cuando la descarga es posible.
        if ($this->capabilities->artifactsFetchable($invoice)) {
            // Informativo: los artifacts se obtienen bajo demanda y su
            // ausencia no altera la vigencia del CFDI ni dispara --fail-on.
            return [new Finding('cfdi_artifacts_missing', Health::Valid, Action::FetchArtifacts)];
        }

        return [];
    }

    private function isIssuePending(Invoice $invoice): bool
    {
        return $invoice->pac_issue_status === 'pending' || $invoice->pac_status === 'pending';
    }

    private function isCancellationPending(Invoice $invoice): bool
    {
        return $invoice->pac_status === 'valid'
            && in_array($invoice->cancellation_status, self::CANCELLATION_PENDING_STATUSES, true);
    }

    private function reviewExplainedByPendingOperation(Invoice $invoice): bool
    {
        return $invoice->pac_issue_status !== 'reconciliation_required'
            && ($this->isIssuePending($invoice) || $this->isCancellationPending($invoice));
    }

    /** Las llaves son deterministas (PacIdentifiers); otra llave apunta a otra factura. */
    private function hasForeignIdempotencyKey(Invoice $invoice): bool
    {
        $companyId = (int) $invoice->company_id;
        $invoiceId = (int) $invoice->id;

        return (filled($invoice->pac_idempotency_key)
                && $invoice->pac_idempotency_key !== PacIdentifiers::idempotencyKey($companyId, $invoiceId))
            || (filled($invoice->pac_draft_idempotency_key)
                && $invoice->pac_draft_idempotency_key !== PacIdentifiers::draftIdempotencyKey($companyId, $invoiceId));
    }

    private function ageInMinutes(?CarbonInterface $since, CarbonImmutable $now): ?int
    {
        if ($since === null) {
            return null;
        }

        return max(0, (int) floor($since->diffInMinutes($now)));
    }

    /**
     * Fase 6.22: el acuse no prueba la cancelación (la prueba es
     * pac_status=canceled), así que nunca escala por sí mismo salvo un
     * bloqueo de identidad en Live. En Facturapi TEST el acuse siempre trae
     * un UUID ajeno (EstatusUUID 205): ahí el bloqueo es esperado e
     * informativo, para no disparar --fail-on en cada cancelación.
     */
    private function receiptFinding(Invoice $invoice): Finding
    {
        $identityMismatch = PacErrorCode::from($invoice->cancellation_receipt_last_error)
            === CancellationReceiptIdentityMismatchException::ERROR_CODE;

        if (! $identityMismatch) {
            return new Finding('cancellation_receipt_retry_pending', Health::Valid, Action::FetchReceipt);
        }

        return config('billing.pac_mode') === PacCredentialGuard::MODE_TEST
            ? new Finding('cancellation_receipt_identity_mismatch', Health::Valid, Action::ManualReview)
            : new Finding('cancellation_receipt_identity_mismatch', Health::Recoverable, Action::ManualReview);
    }

    private function threshold(string $key): int
    {
        return (int) config("billing.health.{$key}");
    }
}
