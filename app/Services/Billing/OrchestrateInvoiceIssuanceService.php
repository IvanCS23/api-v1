<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceLifecycleInconsistentException;
use App\Exceptions\Billing\InvoiceNotReadyForPacException;
use App\Exceptions\Billing\PacReconciliationRequiredException;
use App\Exceptions\InvoiceIssuanceInProgressException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Operación empresarial única "Emitir factura". Prevalida mientras la
 * Invoice aún es Ready, confirma la transición ERP en un lock corto y
 * delega toda preparación/idempotencia/timbrado a la orquestación 6.11.
 */
class OrchestrateInvoiceIssuanceService
{
    public function __construct(
        private readonly InvoiceWorkflow $workflow,
        private readonly InvoicePacReadinessService $readiness,
        private readonly IssueInvoiceToPacService $pacIssuance,
        private readonly InvoiceBusinessCapabilitiesService $capabilities,
    ) {}

    public function issue(Invoice $invoice): Invoice
    {
        $current = $this->requireCurrentTenantInvoice($invoice);

        if ($this->capabilities->isSuccessfulIssue($current)) {
            return $current;
        }

        if ($current->pac_issue_status === 'pending') {
            throw new InvoiceIssuanceInProgressException($current);
        }

        if ($this->capabilities->requiresReview($current)) {
            throw new PacReconciliationRequiredException;
        }

        if (! $this->capabilities->canIssueByState($current)) {
            throw new InvoiceLifecycleInconsistentException('La combinación ERP/PAC no permite emitir la factura de forma segura.');
        }

        if ($current->status === InvoiceStatus::Ready) {
            $this->assertReady($current);
        }

        $issued = $this->workflow->prepareForOrchestratedIssue($current);
        $result = $this->pacIssuance->issue($issued);

        if ($this->capabilities->isSuccessfulIssue($result)) {
            return $result;
        }

        if ($this->capabilities->requiresReview($result)) {
            throw new PacReconciliationRequiredException;
        }

        if ($result->pac_issue_status === 'pending' || $result->pac_status === 'pending') {
            throw new InvoiceIssuanceInProgressException($result);
        }

        throw new InvoiceLifecycleInconsistentException('La emisión no terminó en un estado ERP/PAC confirmado.');
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

    private function requireCurrentTenantInvoice(Invoice $invoice): Invoice
    {
        $tenantId = app(CurrentTenant::class)->id();
        $current = $tenantId !== null
            ? Invoice::withoutGlobalScope(CompanyScope::class)
                ->whereKey($invoice->getKey())
                ->where('company_id', $tenantId)
                ->first()
            : null;

        if ($current === null) {
            throw (new ModelNotFoundException)->setModel(Invoice::class, [$invoice->getKey()]);
        }

        return $current;
    }
}
