<?php

namespace App\Services\Sales;

use App\Enums\SaleStatus;
use App\Exceptions\WorkflowTransitionException;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Única fuente de verdad de las transiciones de estado de una Sale.
 *
 * Confirmed y Cancelled son terminales (Sale::isEditable() ya lo refleja
 * para edición de datos/líneas; aquí se refleja para transiciones de
 * estado). `confirmed_at`/`cancelled_at` los fija exclusivamente este
 * servicio — nunca el endpoint genérico de update (ver
 * UpdateSaleRequest, Fase 4 §5).
 *
 * No duplica el cálculo de totales: `confirm()` solo verifica que los
 * totales ya almacenados sean coherentes con las líneas actuales
 * (deberían serlo siempre, ya que SaleItemController recalcula en cada
 * mutación de línea); no vuelve a calcular ni a corregir nada.
 */
class SaleWorkflow
{
    public function submit(Sale $sale): Sale
    {
        $this->assertTransition($sale, [SaleStatus::Draft], 'enviar a revisión (Pending)');

        $sale->update(['status' => SaleStatus::Pending]);

        return $sale;
    }

    public function confirm(Sale $sale): Sale
    {
        $this->assertTransition($sale, [SaleStatus::Pending], 'confirmar');
        $this->assertConfirmable($sale);

        return DB::transaction(function () use ($sale): Sale {
            $sale->forceFill([
                'status' => SaleStatus::Confirmed,
                'confirmed_at' => now(),
            ])->save();

            return $sale;
        });
    }

    public function cancel(Sale $sale): Sale
    {
        $this->assertTransition($sale, [SaleStatus::Draft, SaleStatus::Pending], 'cancelar');

        $sale->forceFill([
            'status' => SaleStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        return $sale;
    }

    /**
     * @param  array<int, SaleStatus>  $allowedFrom
     */
    private function assertTransition(Sale $sale, array $allowedFrom, string $actionLabel): void
    {
        if (! in_array($sale->status, $allowedFrom, true)) {
            throw new WorkflowTransitionException(sprintf(
                'No se puede %s una venta en estado "%s".',
                $actionLabel,
                $sale->status->value,
            ));
        }
    }

    private function assertConfirmable(Sale $sale): void
    {
        if ($sale->items()->count() === 0) {
            throw new WorkflowTransitionException('No se puede confirmar una venta sin líneas.');
        }

        if ($sale->client === null) {
            throw new WorkflowTransitionException('No se puede confirmar una venta sin un cliente válido.');
        }

        $items = $sale->items()->get();
        $expectedSubtotal = round((float) $items->sum('subtotal'), 2);
        $expectedDiscount = round((float) $items->sum('discount'), 2);
        $expectedTax = round((float) $items->sum('tax_total'), 2);
        $expectedTotal = round((float) $items->sum('total'), 2);

        $coherent = round((float) $sale->subtotal, 2) === $expectedSubtotal
            && round((float) $sale->discount_total, 2) === $expectedDiscount
            && round((float) $sale->tax_total, 2) === $expectedTax
            && round((float) $sale->total, 2) === $expectedTotal;

        if (! $coherent) {
            throw new WorkflowTransitionException('No se puede confirmar una venta con importes incoherentes respecto a sus líneas.');
        }
    }
}
