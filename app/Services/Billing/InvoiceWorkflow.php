<?php

namespace App\Services\Billing;

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
 */
class InvoiceWorkflow
{
    public function markReady(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice): Invoice {
            $locked = $this->lockedInvoice($invoice);

            $this->assertTransition($locked, [InvoiceStatus::Draft], 'marcar como lista');

            $locked->update(['status' => InvoiceStatus::Ready]);

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
