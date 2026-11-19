<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\Scopes\CompanyScope;
use App\Services\Taxes\DecimalMath;

/**
 * Fase 6.35: saldo de una factura PPD (6 decimales, aritmética exacta).
 *
 * - Saldo fiscal (saldo insoluto): total − pagos con REP emitido. Un pago
 *   cuyo REP tiene la cancelación pendiente o rechazada sigue emitido y
 *   sigue contando (ajuste 1); sólo la cancelación aceptada (estado
 *   `cancelled`) devuelve el saldo.
 * - Saldo disponible para asignar: el fiscal menos lo reservado por pagos
 *   en borrador (opcionalmente sin contar uno, al editarlo).
 */
class InvoiceBalance
{
    public function fiscal(Invoice $invoice): string
    {
        return DecimalMath::round(DecimalMath::sub($this->total($invoice), $this->sum($invoice, [PaymentStatus::Issued])), 6);
    }

    public function available(Invoice $invoice, ?int $exceptPaymentId = null): string
    {
        $drafts = $this->sum($invoice, [PaymentStatus::Draft], $exceptPaymentId);

        return DecimalMath::round(DecimalMath::sub($this->fiscal($invoice), $drafts), 6);
    }

    /** Pagos emitidos (sin cancelación aceptada) aplicados a la factura. */
    public function issuedCount(Invoice $invoice): int
    {
        return $this->allocations($invoice, [PaymentStatus::Issued])->count();
    }

    public function hasActivePayments(Invoice $invoice): bool
    {
        return $this->issuedCount($invoice) > 0;
    }

    private function total(Invoice $invoice): string
    {
        return DecimalMath::of($invoice->getAttributes()['total'] ?? '0');
    }

    /** @param  list<PaymentStatus>  $statuses */
    private function sum(Invoice $invoice, array $statuses, ?int $exceptPaymentId = null): string
    {
        $sum = '0';
        foreach ($this->allocations($invoice, $statuses, $exceptPaymentId)->pluck('amount') as $amount) {
            $sum = DecimalMath::add($sum, DecimalMath::of($amount));
        }

        return $sum;
    }

    /** @param  list<PaymentStatus>  $statuses */
    private function allocations(Invoice $invoice, array $statuses, ?int $exceptPaymentId = null)
    {
        return PaymentAllocation::withoutGlobalScope(CompanyScope::class)
            ->where('invoice_id', $invoice->id)
            ->where('company_id', $invoice->company_id)
            ->when($exceptPaymentId !== null, fn ($query) => $query->where('payment_id', '!=', $exceptPaymentId))
            ->whereHas('payment', fn ($query) => $query->withoutGlobalScope(CompanyScope::class)
                ->whereIn('status', array_map(fn (PaymentStatus $status) => $status->value, $statuses)));
    }
}
