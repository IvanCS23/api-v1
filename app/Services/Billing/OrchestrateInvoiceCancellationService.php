<?php

namespace App\Services\Billing;

use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Exceptions\Billing\InvoiceBusinessCancellationIncompleteException;
use App\Exceptions\Billing\InvoiceLifecycleInconsistentException;
use App\Exceptions\Billing\PacReconciliationRequiredException;
use App\Exceptions\InvoiceCannotBeCancelledException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\Tenant\CurrentTenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** Operación empresarial única "Cancelar factura". */
class OrchestrateInvoiceCancellationService
{
    public function __construct(
        private readonly InvoiceWorkflow $workflow,
        private readonly InvoiceBusinessCapabilitiesService $capabilities,
    ) {}

    public function cancel(
        Invoice $invoice,
        ?CfdiCancellationMotive $motive = null,
        ?string $substitutionUuid = null,
    ): Invoice {
        $current = $this->requireCurrentTenantInvoice($invoice);

        if ($current->status === InvoiceStatus::Cancelled
            && ($this->capabilities->hasNoFiscalIdentity($current)
                || ($this->capabilities->hasCompleteFiscalIdentity($current) && $current->pac_status === 'canceled'))) {
            return $current;
        }

        if (in_array($current->cancellation_status, ['pending', 'verifying'], true)) {
            return $current;
        }

        if ($this->capabilities->requiresReview($current)) {
            throw new PacReconciliationRequiredException;
        }

        return match ($this->capabilities->cancellationMode($current)) {
            'erp_only', 'erp_convergence' => $this->workflow->convergeOrchestratedCancellation($current),
            'pac' => $this->cancelWithPac($current, $motive, $substitutionUuid),
            default => throw new InvoiceLifecycleInconsistentException(
                'La combinación ERP/PAC no permite cancelar la factura de forma segura.',
            ),
        };
    }

    private function cancelWithPac(
        Invoice $invoice,
        ?CfdiCancellationMotive $motive,
        ?string $substitutionUuid,
    ): Invoice {
        if ($motive === null) {
            throw new InvoiceCannotBeCancelledException($invoice, 'la cancelación fiscal requiere motivo SAT');
        }

        $result = $this->workflow->cancelWithPac($invoice, $motive, $substitutionUuid);

        if ($result->pac_status === 'canceled') {
            return $this->workflow->convergeOrchestratedCancellation($result);
        }

        if ($this->capabilities->requiresReview($result)
            || in_array($result->cancellation_status, ['pending', 'verifying'], true)) {
            return $result;
        }

        if (in_array($result->cancellation_status, ['rejected', 'expired'], true)) {
            throw new InvoiceBusinessCancellationIncompleteException('La cancelación fiscal no fue aceptada.');
        }

        throw new InvoiceLifecycleInconsistentException('La cancelación no terminó en un estado ERP/PAC reconocido.');
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
