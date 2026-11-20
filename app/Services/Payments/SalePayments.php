<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;
use App\Models\PaymentAllocation;
use App\Models\Sale;
use App\Models\Scopes\CompanyScope;

/**
 * Fase 6.35 (D8): ¿alguna factura de la venta (incluso borrada) tiene
 * asignaciones de pagos no cancelados (borrador o REP emitido)?
 *
 * Con el diseño actual no debería pasar: una venta sólo se vuelve a facturar
 * si su factura anterior se borró (sólo se borran borradores) y los pagos
 * sólo se asignan a facturas timbradas. Si aun así ocurre, la venta no se
 * recalcula ni se factura (SALE_HAS_PAYMENTS) y se revisa a mano.
 */
final class SalePayments
{
    public const CODE = 'SALE_HAS_PAYMENTS';

    public const MESSAGE = 'Una factura de esta venta tiene pagos registrados; no se puede recalcular ni volver a facturar. Revísala con el área contable.';

    public static function exist(Sale $sale): bool
    {
        return PaymentAllocation::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $sale->company_id)
            ->whereHas('invoice', fn ($invoice) => $invoice->withTrashed()->where('sale_id', $sale->id))
            ->whereHas('payment', fn ($payment) => $payment->withoutGlobalScope(CompanyScope::class)
                ->whereIn('status', [PaymentStatus::Draft->value, PaymentStatus::Issued->value]))
            ->exists();
    }

    /** @return array{code: string, field: string, message: string} */
    public static function error(): array
    {
        return ['code' => self::CODE, 'field' => 'payments', 'message' => self::MESSAGE];
    }
}
