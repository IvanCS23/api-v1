<?php

namespace App\Services\Billing;

use App\Enums\CfdiCancellationMotive;
use App\Enums\InvoiceStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de verdad de las transiciones de estado de una Invoice:
 * Draft → Ready → Issued, y Draft/Ready → Cancelled. Issued es terminal
 * y completamente inmutable — este workflow no expone ningún método
 * para salir de ese estado, y ninguna otra parte del sistema fija
 * `issued_at` salvo `issue()` aquí.
 *
 * Concurrencia: mismo patrón endurecido que SaleWorkflow/QuoteWorkflow
 * (Fase 4 — cierre): cada transición corre dentro de DB::transaction(),
 * relee la Invoice con lockForUpdate() + `where('company_id', ...)`
 * explícito (con el company_id de la instancia ya tenant-scoped
 * recibida, nunca del request), y valida/actualiza sobre esa relectura
 * — nunca sobre la instancia recibida como parámetro.
 *
 * `issueToPac()` (Fase 6.2) es un punto de entrada adicional, distinto
 * de `issue()`: `issue()` es la transición local Ready → Issued (sin
 * PAC, sin cambios en esta fase); `issueToPac()` opera sobre una Invoice
 * ya Issued y dispara el timbrado real ante el PAC activo. Se agrega
 * aquí (en vez de un IssueInvoiceWorkflow separado) para mantener un
 * único punto de orquestación por agregado — toda la validación y
 * persistencia del timbrado en sí vive en IssueInvoiceService, que este
 * método únicamente invoca.
 */
class InvoiceWorkflow
{
    public function __construct(
        private readonly IssueInvoiceService $issueInvoiceService,
        private readonly ReconcileInvoiceWithPacService $reconcileInvoiceWithPacService,
        private readonly CancelInvoiceWithPacService $cancelInvoiceWithPacService,
    ) {}

    public function issueToPac(Invoice $invoice): Invoice
    {
        return $this->issueInvoiceService->issue($invoice);
    }

    public function reconcileWithPac(Invoice $invoice, bool $throwOnFailure = false): Invoice
    {
        return $this->reconcileInvoiceWithPacService->reconcile($invoice, $throwOnFailure);
    }

    public function cancelWithPac(
        Invoice $invoice,
        CfdiCancellationMotive $motive,
        ?string $substitutionUuid = null,
    ): Invoice {
        return $this->cancelInvoiceWithPacService->cancel($invoice, $motive, $substitutionUuid);
    }

    public function markReady(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);

            $this->assertTransition($locked, [InvoiceStatus::Draft], 'marcar como lista');

            $locked->update(['status' => InvoiceStatus::Ready]);

            return $locked;
        });
    }

    /**
     * Preparación idempotente para la operación empresarial de emisión.
     * El lock sólo protege la transición ERP; ningún HTTP ocurre aquí.
     */
    public function prepareForOrchestratedIssue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);

            if ($locked->status === InvoiceStatus::Issued) {
                return $locked;
            }

            $this->assertTransition($locked, [InvoiceStatus::Ready], 'preparar para emisión');

            $locked->forceFill([
                'status' => InvoiceStatus::Issued,
                'issued_at' => $locked->issued_at ?? now(),
            ])->save();

            return $locked;
        });
    }

    /**
     * Converge el ERP sólo cuando no existe identidad CFDI o cuando el
     * PAC ya confirmó pac_status=canceled. Nunca convierte silently un
     * CFDI valid en una cancelación ERP final.
     */
    public function convergeOrchestratedCancellation(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);
            $hasNoFiscalIdentity = blank($locked->pac_external_id)
                && blank($locked->cfdi_uuid)
                && $locked->pac_status === null
                && $locked->pac_issue_status !== 'succeeded'
                && $locked->pac_issue_status !== 'pending'
                && $locked->pac_issue_status !== 'reconciliation_required'
                && ! $locked->pac_reconciliation_required;
            $hasConfirmedFiscalCancellation = filled($locked->pac_external_id)
                && filled($locked->cfdi_uuid)
                && $locked->pac_status === 'canceled';

            if (! $hasNoFiscalIdentity && ! $hasConfirmedFiscalCancellation) {
                throw new WorkflowTransitionException(sprintf(
                    'No se puede converger la cancelación de la factura [%d] sin confirmación fiscal segura.',
                    $locked->id,
                ));
            }

            if ($locked->status === InvoiceStatus::Cancelled) {
                return $locked;
            }

            if (! in_array($locked->status, [InvoiceStatus::Draft, InvoiceStatus::Ready, InvoiceStatus::Issued], true)) {
                throw new WorkflowTransitionException(sprintf(
                    'No se puede converger la cancelación de una factura en estado "%s".',
                    $locked->status->value,
                ));
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => $locked->cancelled_at ?? now(),
            ])->save();

            return $locked;
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);

            $this->assertTransition($locked, [InvoiceStatus::Ready], 'emitir');

            $locked->forceFill([
                'status' => InvoiceStatus::Issued,
                'issued_at' => now(),
            ])->save();

            return $locked;
        });
    }

    public function cancel(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);

            $this->assertTransition($locked, [InvoiceStatus::Draft, InvoiceStatus::Ready], 'cancelar');

            $locked->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
            ])->save();

            return $locked;
        });
    }

    private function lockedInvoice(Invoice $invoice): Invoice
    {
        return Invoice::withoutGlobalScope(CompanyScope::class)
            ->whereKey($invoice->getKey())
            ->where('company_id', $invoice->company_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param  array<int, InvoiceStatus>  $allowedFrom
     */
    private function assertTransition(Invoice $invoice, array $allowedFrom, string $actionLabel): void
    {
        if (! in_array($invoice->status, $allowedFrom, true)) {
            throw new WorkflowTransitionException(sprintf(
                'No se puede %s una factura en estado "%s".',
                $actionLabel,
                $invoice->status->value,
            ));
        }
    }
}
